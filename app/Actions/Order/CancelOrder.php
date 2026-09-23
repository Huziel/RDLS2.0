<?php

namespace App\Actions\Order;

use App\Actions\Inventory\RestoreInventory;
use App\Models\Cart;
use App\Models\Client;
use App\Models\LoyaltyPoint;
use App\Models\LoyaltyTransaction;
use App\Models\OrderPayment;
use App\Models\OrderReturn;
use App\Models\ProductStock;
use App\Models\PurchaseOrder;
use App\Models\ShippingOrder;
use App\Services\OrderIdempotency;
use Illuminate\Support\Facades\DB;

class CancelOrder
{
    public function __construct(
        private readonly RestoreInventory $restoreInventory,
        private readonly OrderIdempotency $idempotency,
    ) {}

    /**
     * Cancels an online order exactly once. When the order is cancelled for
     * the first time the consumed stock is restored (grouped by product) and
     * loyalty movements are reversed idempotently. Cancelling an already
     * cancelled order is a no-op and never restores stock twice.
     *
     * @return array{status: string, idempotent: bool, restocked: bool, reversed: bool, refund_required: bool}
     */
    public function __invoke(
        PurchaseOrder $order,
        ?int $actorId = null,
        string $actorType = 'system',
        ?string $idempotencyKey = null,
    ): array {
        return DB::transaction(function () use ($order, $actorId, $actorType, $idempotencyKey) {
            $fresh = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $shipping = ShippingOrder::where('ordenCompra', $fresh->id)->lockForUpdate()->first();
            $payment = OrderPayment::where('order_id', $fresh->id)->lockForUpdate()->first();
            $return = OrderReturn::where('order_id', $fresh->id)->lockForUpdate()->first();
            $fresh->setRelation('payment', $payment);
            $storeId = (int) ($payment?->store_id ?? $fresh->store?->id);
            $hash = $this->idempotency->hash([]);
            $eventKey = $idempotencyKey === null ? null : $this->idempotency->eventKey($fresh, 'cancel', $idempotencyKey);
            if ($eventKey !== null && ($event = $this->idempotency->find($eventKey, $hash))) {
                return array_merge($event->response_data, [
                    'idempotent' => true,
                    'restocked' => false,
                    'reversed' => false,
                ]);
            }
            if ($actorType === 'customer' && $fresh->isPaid()) {
                return ['error' => 'Una orden pagada no puede cancelarse publicamente.', 'status' => 422];
            }
            // Fail-closed: un pago con comprobante en revision no se cancela
            // ni restoca hasta que finanzas lo resuelva (evita doble salida).
            if ($payment !== null && $payment->status === 'proof_submitted') {
                return ['error' => 'El pago tiene un comprobante en revision.', 'status' => 409];
            }

            $wasPaid = $fresh->isPaid();
            $restored = false;
            $reversed = false;
            $items = Cart::where('orderC', $fresh->order)->orderBy('id')->lockForUpdate()->get();
            $fresh->extraCharges()->orderBy('id')->lockForUpdate()->get();
            $departed = $this->departed($shipping);
            if (! $departed && $fresh->restock_key === null) {
                ProductStock::whereIn('idProd', $items->pluck('product')->filter()->unique()->sort()->values())
                    ->orderBy('idProd')->lockForUpdate()->get();
            }
            if ($fresh->isCancelled()) {
                $response = [
                    'status' => 'cancelled',
                    'idempotent' => true,
                    'restocked' => false,
                    'reversed' => false,
                    'refund_required' => $wasPaid,
                ];
                if ($eventKey !== null) {
                    $this->idempotency->record($fresh, $storeId, $actorId, $actorType, 'order_cancelled', $eventKey, $hash, ['status' => 'cancelled'], ['status' => 'cancelled'], 200, $response);
                }

                return $response;
            }

            if (! $departed && $fresh->restock_key === null) {
                if ($items->isNotEmpty()) {
                    $quantities = $items->groupBy('product')
                        ->map(fn ($group) => (float) $group->sum('cant'));
                    ($this->restoreInventory)($quantities);
                    $restored = true;
                }
                $reversed = $this->reverseLoyalty($fresh, $items);
                $fresh->restock_key = hash('sha256', 'restock:'.$fresh->order);
            } elseif ($departed && ! $return) {
                $return = OrderReturn::create([
                    'order_id' => $fresh->id,
                    'store_id' => $storeId,
                    'status' => 'pending',
                ]);
            }

            if ($shipping && (string) $shipping->status !== ShippingOrder::STATUS_CANCELLED) {
                $shipping->update(['status' => ShippingOrder::STATUS_CANCELLED]);
            }
            if ($wasPaid && $payment && $payment->status !== 'refunded') {
                $payment->update(['status' => 'refund_pending', 'refund_requested_at' => now()]);
            }
            $fresh->order_state = PurchaseOrder::STATE_CANCELLED;
            $fresh->cancelled_at = now();
            $fresh->save();

            $response = [
                'status' => 'cancelled',
                'idempotent' => false,
                'restocked' => $restored,
                'reversed' => $reversed,
                'refund_required' => $wasPaid,
                'return_pending' => $departed,
            ];
            if ($eventKey !== null) {
                $this->idempotency->record($fresh, $storeId, $actorId, $actorType, 'order_cancelled', $eventKey, $hash, ['status' => 'pending'], ['status' => 'cancelled', 'payment_status' => $payment?->fresh()->status, 'return_status' => $return?->status], 200, $response);
            }

            return $response;
        });
    }

    private function departed(?ShippingOrder $shipping): bool
    {
        if (! $shipping) {
            return false;
        }
        // Fail-closed: solo 'not_departed' permite restock automatico;
        // 'departed', 'unknown' o estado nulo tratan la salida como ocurrida.
        if ($shipping->departure_state === 'not_departed') {
            return false;
        }

        return true;
    }

    private function reverseLoyalty(PurchaseOrder $order, iterable $items): bool
    {
        $store = $order->store;
        if (! $store) {
            return false;
        }

        $reversed = false;

        if ($order->checkout_key && $order->loyalty_discount > 0) {
            $redeemed = LoyaltyTransaction::where('store_id', $store->id)
                ->where('type', 'checkout_redeem')
                ->where('reference', "checkout:{$order->checkout_key}")
                ->first();

            if ($redeemed && $redeemed->points < 0) {
                $client = Client::find($redeemed->client_id);
                if ($client && LoyaltyPoint::addPoints(
                    $store->id,
                    $client->id,
                    abs((int) $redeemed->points),
                    'redeem_reverse',
                    "Devolucion de puntos por cancelacion de {$order->order}",
                    "checkout:{$order->checkout_key}",
                )) {
                    $reversed = true;
                }
            }
        }

        $earned = LoyaltyTransaction::where('store_id', $store->id)
            ->where('type', 'checkout_earn')
            ->where('reference', $order->order)
            ->first();

        if ($earned && $earned->points > 0) {
            $client = Client::find($earned->client_id);
            if ($client && LoyaltyPoint::redeemPoints(
                $store->id,
                $client->id,
                (int) $earned->points,
                "earn_reverse:{$order->order}",
                'earn_reverse',
            )) {
                $reversed = true;
            }
        }

        return $reversed;
    }
}
