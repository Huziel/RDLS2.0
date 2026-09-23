<?php

namespace App\Actions\Order;

use App\Models\OrderPayment;
use App\Models\PurchaseOrder;
use App\Services\CanonicalOrderAmount;
use App\Services\OrderIdempotency;
use Illuminate\Support\Facades\DB;

class ConfirmFullRefund
{
    public function __construct(
        private readonly CanonicalOrderAmount $amounts,
        private readonly OrderIdempotency $idempotency,
    ) {}

    public function __invoke(PurchaseOrder $order, int $storeId, int $actorId, string $key, string $reference): array
    {
        $hash = $this->idempotency->hash(['reference' => $reference]);
        $eventKey = $this->idempotency->eventKey($order, 'refund', $key);

        return DB::transaction(function () use ($order, $storeId, $actorId, $reference, $hash, $eventKey) {
            $fresh = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $fresh->shippingOrder()->lockForUpdate()->first();
            $payment = OrderPayment::where('order_id', $fresh->id)->lockForUpdate()->first();
            $fresh->returnRecord()->lockForUpdate()->first();
            if (! $payment || (int) $payment->store_id !== $storeId) {
                return ['error' => 'No se conoce el importe canonico cobrado.', 'status_code' => 422];
            }
            $event = $this->idempotency->find($eventKey, $hash);
            if ($event) {
                return array_merge($event->response_data, ['idempotent' => true]);
            }
            if ($payment->method === 'legacy_unknown') {
                return ['error' => 'No se conoce el importe canonico cobrado.', 'status_code' => 422];
            }
            if ($payment->status !== 'refund_pending') {
                return ['error' => 'El pago no esta pendiente de reembolso.', 'status_code' => 409];
            }
            $paid = $this->amounts->cents($payment->amount_paid);
            if ($paid <= 0 || $this->amounts->cents($payment->amount_refunded) > $paid) {
                return ['error' => 'El importe cobrado no es reembolsable.', 'status_code' => 422];
            }

            $payment->update([
                'status' => 'refunded',
                'amount_refunded' => $this->amounts->money($paid),
                'refunded_at' => now(),
                'refunded_by' => $actorId,
                'refund_reference' => $reference,
            ]);
            $response = [
                'order' => $fresh->order,
                'status' => 'refunded',
                'confirmation' => 'manual_external',
                'amount_refunded' => $this->amounts->money($paid),
            ];
            $this->idempotency->record($fresh, $storeId, $actorId, 'store_owner', 'external_manual_refund_confirmed', $eventKey, $hash, ['status' => 'refund_pending'], ['status' => 'refunded'], 200, $response);

            return $response + ['idempotent' => false];
        });
    }
}
