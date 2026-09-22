<?php

namespace App\Actions\Order;

use App\Actions\Inventory\RestoreInventory;
use App\Models\Cart;
use App\Models\Client;
use App\Models\LoyaltyPoint;
use App\Models\LoyaltyTransaction;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;

class CancelOrder
{
    public function __construct(private readonly RestoreInventory $restoreInventory) {}

    /**
     * Cancels an online order exactly once. When the order is cancelled for
     * the first time the consumed stock is restored (grouped by product) and
     * loyalty movements are reversed idempotently. Cancelling an already
     * cancelled order is a no-op and never restores stock twice.
     *
     * @return array{status: string, idempotent: bool, restocked: bool, reversed: bool, refund_required: bool}
     */
    public function __invoke(PurchaseOrder $order): array
    {
        return DB::transaction(function () use ($order) {
            $fresh = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($fresh->isCancelled()) {
                return [
                    'status' => 'cancelled',
                    'idempotent' => true,
                    'restocked' => false,
                    'reversed' => false,
                    'refund_required' => $fresh->isPaid(),
                ];
            }

            $wasPaid = $fresh->isPaid();

            $restored = false;
            $reversed = false;
            $firstCancel = false;

            if ($fresh->restock_key === null) {
                $items = Cart::where('orderC', $fresh->order)
                    ->orderBy('id')
                    ->get();

                if ($items->isNotEmpty()) {
                    $quantities = $items->groupBy('product')
                        ->map(fn ($group) => (float) $group->sum('cant'));

                    ($this->restoreInventory)($quantities);
                    $restored = true;
                }

                $reversed = $this->reverseLoyalty($fresh, $items);

                $fresh->update([
                    'order_state' => PurchaseOrder::STATE_CANCELLED,
                    'cancelled_at' => now(),
                    'restock_key' => hash('sha256', 'restock:'.$fresh->order),
                ]);
                $firstCancel = true;
            } else {
                $fresh->update(['order_state' => PurchaseOrder::STATE_CANCELLED]);
            }

            return [
                'status' => 'cancelled',
                'idempotent' => ! $firstCancel,
                'restocked' => $restored,
                'reversed' => $reversed,
                'refund_required' => $wasPaid,
            ];
        });
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
