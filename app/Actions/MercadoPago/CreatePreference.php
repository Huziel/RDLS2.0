<?php

namespace App\Actions\MercadoPago;

use App\Exceptions\PaymentPreferenceNotAllowed;
use App\Models\MercadoPagoAccount;
use App\Models\MercadoPagoPayment;
use App\Models\OrderPayment;
use App\Models\PurchaseOrder;
use App\Models\Store;
use App\Services\CanonicalOrderAmount;
use App\Services\OrderIdempotency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CreatePreference
{
    public function __construct(
        private readonly CanonicalOrderAmount $amounts,
        private readonly OrderIdempotency $idempotency,
    ) {}

    /** @return array{preference_id:string,init_point:string,sandbox_init_point:string,idempotent:bool} */
    public function __invoke(
        PurchaseOrder $order,
        MercadoPagoAccount $account,
        Store $store,
        string $idempotencyKey,
        ?int $actorId = null,
        string $actorType = 'customer',
    ): array {
        return DB::transaction(function () use ($order, $account, $store, $actorId, $actorType) {
            $fresh = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $fresh->shippingOrder()->lockForUpdate()->first();
            $payment = OrderPayment::where('order_id', $fresh->id)->lockForUpdate()->first();
            $fresh->returnRecord()->lockForUpdate()->first();
            if (! $payment || (int) $payment->store_id !== (int) $store->id || $payment->method !== 'mercado_pago') {
                throw PaymentPreferenceNotAllowed::forNonMercadoPagoOrder();
            }
            // Buscar el evento de replay ANTES de las guardas de estado para
            // que un mismo key+payload devuelva la respuesta original aunque
            // la orden ya este cancelada o pagada entre llamadas.
            $snapshotProbe = $this->amounts->snapshot($fresh, $payment, false, true);
            $probeAmountCents = $this->amounts->cents($snapshotProbe->amount_due);
            $probeAmount = $this->amounts->money($probeAmountCents);
            $probeProviderKey = hash('sha256', implode('|', [
                'mercado_pago_preference',
                $snapshotProbe->id,
                $probeAmount,
                $snapshotProbe->currency,
            ]));
            $probeEventKey = "order:{$fresh->id}:mercado-pago-preference:{$probeProviderKey}";
            $probeHash = $this->idempotency->hash([
                'payment_id' => $snapshotProbe->id,
                'amount' => $probeAmount,
                'currency' => $snapshotProbe->currency,
            ]);
            if ($event = $this->idempotency->find($probeEventKey, $probeHash)) {
                return array_merge($event->response_data, ['idempotent' => true]);
            }
            if ($fresh->isCancelled() || in_array($payment->status, OrderPayment::PAID_STATUSES, true)) {
                throw PaymentPreferenceNotAllowed::forOrderState();
            }
            $payment = $this->amounts->snapshot($fresh, $payment, true, true);
            $amountCents = $this->amounts->cents($payment->amount_due);
            $amount = $this->amounts->money($amountCents);
            $providerKey = hash('sha256', implode('|', [
                'mercado_pago_preference',
                $payment->id,
                $amount,
                $payment->currency,
            ]));
            $eventKey = "order:{$fresh->id}:mercado-pago-preference:{$providerKey}";
            $requestHash = $this->idempotency->hash([
                'payment_id' => $payment->id,
                'amount' => $amount,
                'currency' => $payment->currency,
            ]);

            if ($event = $this->idempotency->find($eventKey, $requestHash)) {
                return array_merge($event->response_data, ['idempotent' => true]);
            }
            $legacy = MercadoPagoPayment::where('orderP', $fresh->order)->lockForUpdate()->first();
            if ($legacy && $legacy->preference !== '') {
                $result = [
                    'preference_id' => $legacy->preference,
                    'init_point' => '',
                    'sandbox_init_point' => '',
                ];
                $this->idempotency->record($fresh, $store->id, $actorId, $actorType, 'mercado_pago_preference_recovered', $eventKey, $requestHash, null, ['payment_status' => 'pending'], 200, $result);

                return $result + ['idempotent' => true];
            }

            $baseUrl = rtrim((string) config('services.mercadopago.base_url'), '/');
            $storeName = mb_substr((string) ($store->extra?->nombreTienda ?: $store->serial), 0, 25);
            $response = Http::withToken($account->secretKey)
                ->acceptJson()
                ->asJson()
                ->withHeaders(['X-Idempotency-Key' => $providerKey])
                ->timeout(12)
                ->post("{$baseUrl}/checkout/preferences", [
                    'items' => [[
                        'id' => (string) $fresh->id,
                        'title' => 'Orden '.$fresh->order,
                        'quantity' => 1,
                        'unit_price' => (float) $amount,
                        'currency_id' => $payment->currency,
                    ]],
                    'external_reference' => $fresh->order,
                    'notification_url' => $this->notificationUrl(),
                    'back_urls' => [
                        'success' => $this->returnUrl($store, $fresh),
                        'pending' => $this->returnUrl($store, $fresh),
                        'failure' => $this->returnUrl($store, $fresh),
                    ],
                    'auto_return' => 'approved',
                    'binary_mode' => true,
                    'statement_descriptor' => $storeName,
                ]);

            if (! $response->successful() || ! is_string($response->json('id')) || $response->json('id') === '') {
                throw new RuntimeException('No fue posible crear la preferencia de pago.');
            }
            $result = [
                'preference_id' => (string) $response->json('id'),
                'init_point' => (string) $response->json('init_point', ''),
                'sandbox_init_point' => (string) $response->json('sandbox_init_point', ''),
            ];
            MercadoPagoPayment::updateOrCreate(
                ['orderP' => $fresh->order],
                ['status' => '0', 'preference' => $result['preference_id'], 'fecha' => now()->format('Y-m-d H:i:s')],
            );
            $this->idempotency->record($fresh, $store->id, $actorId, $actorType, 'mercado_pago_preference_created', $eventKey, $requestHash, null, ['payment_status' => 'pending'], 200, $result);

            return $result + ['idempotent' => false];
        }, 3);
    }

    private function notificationUrl(): string
    {
        $override = (string) config('services.mercadopago.notification_url');

        return $override !== '' ? $override : rtrim((string) config('app.url'), '/').'/api/v1/payments/webhook';
    }

    private function returnUrl(Store $store, PurchaseOrder $order): string
    {
        $frontend = rtrim((string) config('services.mercadopago.frontend_url'), '/');
        if ($frontend === '') {
            $frontend = rtrim((string) config('app.url'), '/');
        }

        return "{$frontend}/store/{$store->serial}/thanks?order={$order->order}";
    }
}
