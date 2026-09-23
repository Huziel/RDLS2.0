<?php

namespace App\Actions\Order;

use App\Models\Cart;
use App\Models\OrderPayment;
use App\Models\OrderPaymentProof;
use App\Models\PurchaseOrder;
use App\Services\CanonicalOrderAmount;
use App\Services\OrderIdempotency;
use Illuminate\Support\Facades\DB;

class ConfirmManualPayment
{
    public function __construct(
        private readonly CanonicalOrderAmount $amounts,
        private readonly OrderIdempotency $idempotency,
    ) {}

    public function __invoke(PurchaseOrder $order, int $storeId, int $actorId, string $key, array $payload): array
    {
        $requestHash = $this->idempotency->hash($payload);
        $eventKey = $this->idempotency->eventKey($order, 'confirm-payment', $key);

        return DB::transaction(function () use ($order, $storeId, $actorId, $payload, $requestHash, $eventKey) {
            $fresh = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $fresh->shippingOrder()->lockForUpdate()->first();
            $payment = OrderPayment::where('order_id', $fresh->id)->lockForUpdate()->first();
            $fresh->returnRecord()->lockForUpdate()->first();
            $event = $this->idempotency->find($eventKey, $requestHash);
            if ($event) {
                return array_merge($event->response_data, ['idempotent' => true]);
            }
            if ($fresh->isCancelled()) {
                return ['error' => 'Una orden cancelada no puede confirmarse.', 'status_code' => 409];
            }
            if (! $payment || (int) $payment->store_id !== $storeId) {
                return ['error' => 'La orden no tiene un metodo de pago canonico.', 'status_code' => 422];
            }
            if ($payment->method === 'mercado_pago') {
                return ['error' => 'Este metodo no admite confirmacion manual.', 'status_code' => 422];
            }

            $requestedMethod = $payload['method'] ?? null;
            $convertedFromLegacy = false;
            if ($payment->method === 'legacy_unknown') {
                // Conversion one-shot de una orden legada: el dueno declara el
                // metodo real y la referencia; sin heuristica.
                if (! in_array($requestedMethod, ['cash', 'bank_transfer', 'cash_on_delivery'], true)) {
                    return ['error' => 'Debes declarar el metodo de cobro real: cash, bank_transfer o cash_on_delivery.', 'status_code' => 422];
                }
                $reference = $payload['reference'] ?? null;
                if (! is_string($reference) || trim($reference) === '') {
                    return ['error' => 'Se requiere la referencia de cobro para confirmar una orden legada.', 'status_code' => 422];
                }
                if ($requestedMethod === 'bank_transfer') {
                    $proof = isset($payload['proof_id'])
                        ? OrderPaymentProof::whereKey($payload['proof_id'])->lockForUpdate()->first()
                        : null;
                    if (! $proof || (int) $proof->payment_id !== (int) $payment->id || (int) $proof->store_id !== $storeId) {
                        return ['error' => 'Se requiere un comprobante de esta orden para transferencias.', 'status_code' => 422];
                    }
                }
                $payment->update(['method' => $requestedMethod]);
                $payment = $payment->refresh();
                $convertedFromLegacy = true;
            } elseif ($requestedMethod !== null) {
                return ['error' => 'El metodo de cobro no puede cambiarse.', 'status_code' => 422];
            }

            if (! $convertedFromLegacy && $payment->method === 'bank_transfer') {
                $proof = isset($payload['proof_id'])
                    ? OrderPaymentProof::whereKey($payload['proof_id'])->lockForUpdate()->first()
                    : null;
                if (! $proof || (int) $proof->payment_id !== (int) $payment->id || (int) $proof->store_id !== $storeId) {
                    return ['error' => 'Se requiere un comprobante de esta orden.', 'status_code' => 422];
                }
            }

            $payment = $this->amounts->snapshot($fresh, $payment, true, true);
            if ($payment->representsCollectedFunds()) {
                $response = ['order' => $fresh->order, 'status' => 'paid'];
                $this->idempotency->record($fresh, $storeId, $actorId, 'store_owner', 'manual_payment_confirmed', $eventKey, $requestHash, ['status' => $payment->status], ['status' => $payment->status], 200, $response);

                return $response + ['idempotent' => true];
            }

            $reference = $payload['reference'] ?? null;
            $payment->update([
                'status' => 'paid',
                'amount_paid' => $payment->amount_due,
                'paid_at' => now(),
                'paid_by' => $actorId,
                'bank_reference' => $payment->method === 'bank_transfer' ? ($reference ?: $payment->bank_reference) : null,
                'cash_reference' => in_array($payment->method, ['cash', 'cash_on_delivery'], true) ? $reference : null,
            ]);
            Cart::where('orderC', $fresh->order)->where('variation', $fresh->serial)->update(['status' => '3']);
            $fresh->update(['order_state' => PurchaseOrder::STATE_PAID]);
            $response = ['order' => $fresh->order, 'status' => 'paid'];
            $this->idempotency->record($fresh, $storeId, $actorId, 'store_owner', 'manual_payment_confirmed', $eventKey, $requestHash, ['status' => 'pending'], ['status' => 'paid'], 200, $response);

            return $response + ['idempotent' => false];
        });
    }
}
