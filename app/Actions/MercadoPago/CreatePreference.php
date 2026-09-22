<?php

namespace App\Actions\MercadoPago;

use App\Models\MercadoPagoAccount;
use App\Models\MercadoPagoPayment;
use App\Models\PurchaseOrder;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CreatePreference
{
    /**
     * Creates a real Checkout Pro preference for an online order using the
     * merchant's own access token. The payment is never charged here: a
     * preference only makes MercadoPago render its checkout. Confirmation
     * happens exclusively through the verified webhook.
     *
     * @return array{preference_id: string, init_point: string, sandbox_init_point: string}
     */
    public function __invoke(PurchaseOrder $order, MercadoPagoAccount $account, Store $store): array
    {
        $baseUrl = rtrim((string) config('services.mercadopago.base_url'), '/');
        $order->loadMissing('cartItems.productData');

        $items = $order->cartItems->map(fn ($item) => [
            'id' => (string) $item->product,
            'title' => ($item->productData->keyy ?? 'Producto #'.$item->product),
            'quantity' => max(1, (int) round((float) $item->cant)),
            'unit_price' => $this->unitPrice($item),
            'currency_id' => 'MXN',
        ])->filter(fn (array $line) => $line['unit_price'] >= 0)->values()->all();

        if (empty($items)) {
            throw new RuntimeException('La orden no contiene productos validos para cobrar.');
        }

        $storeName = mb_substr((string) ($store->extra?->nombreTienda ?: $store->serial), 0, 25);

        $response = Http::withToken($account->secretKey)
            ->acceptJson()
            ->asJson()
            ->withHeaders(['Idempotency-Key' => hash('sha256', $order->order)])
            ->timeout(12)
            ->post("{$baseUrl}/checkout/preferences", [
                'items' => $items,
                'external_reference' => $order->order,
                'notification_url' => $this->notificationUrl(),
                'back_urls' => [
                    'success' => $this->returnUrl($store, $order),
                    'pending' => $this->returnUrl($store, $order),
                    'failure' => $this->returnUrl($store, $order),
                ],
                'auto_return' => 'approved',
                'binary_mode' => true,
                'statement_descriptor' => $storeName,
                'payer' => [
                    'name' => mb_substr((string) $order->nombre, 0, 256),
                    'phone' => ['area_code' => '', 'number' => mb_substr((string) $order->tel, 0, 20)],
                ],
            ]);

        if ($response->status() >= 500) {
            throw new RuntimeException('MercadoPago no esta disponible en este momento.');
        }
        if ($response->status() === 401) {
            throw new RuntimeException('Las credenciales de MercadoPago no son validas.');
        }
        if (! $response->successful() || ! is_string($response->json('id')) || $response->json('id') === '') {
            throw new RuntimeException('No fue posible crear la preferencia de pago.');
        }

        $preferenceId = (string) $response->json('id');

        MercadoPagoPayment::updateOrCreate(
            ['orderP' => $order->order],
            [
                'status' => '0',
                'preference' => $preferenceId,
                'fecha' => now()->format('Y-m-d H:i:s'),
            ],
        );

        return [
            'preference_id' => $preferenceId,
            'init_point' => (string) $response->json('init_point', ''),
            'sandbox_init_point' => (string) $response->json('sandbox_init_point', ''),
        ];
    }

    private function unitPrice($item): float
    {
        $quantity = max(1, (int) round((float) $item->cant));

        return (float) round(((float) $item->price) / $quantity, 2);
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
