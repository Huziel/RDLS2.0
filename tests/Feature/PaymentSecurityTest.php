<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\MercadoPagoAccount;
use App\Models\MercadoPagoPayment;
use App\Models\OrderPayment;
use App\Models\PurchaseOrder;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithSalesSchema;
use Tests\TestCase;

class PaymentSecurityTest extends TestCase
{
    use InteractsWithSalesSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesSchema();
    }

    public function test_payment_preference_is_scoped_to_the_authenticated_store_and_retryable(): void
    {
        [$user, $store] = $this->signInStore('payments@example.test');
        MercadoPagoAccount::create([
            'idLog' => $user->id,
            'secretKey' => 'secret',
            'publicKey' => 'public',
            'merchantId' => '123',
        ]);
        $order = $this->order($store, 'OWN-ORDER', 50, 20);
        Cart::create([
            'product' => 1,
            'price' => 50,
            'dom' => $store->createdby,
            'user' => 'payment-cart',
            'variation' => $store->serial,
            'cant' => 1,
            'status' => 2,
            'orderC' => $order->order,
        ]);
        $foreignStore = Store::create([
            'serial' => 'FOREIGN-PAYMENTS',
            'createdby' => 'foreign@example.test',
        ]);
        $foreignOrder = $this->order($foreignStore, 'FOREIGN-ORDER', 100);
        Http::fake([
            'api.mercadopago.com/checkout/preferences' => Http::response([
                'id' => 'PREF-OWN',
                'init_point' => 'https://sandbox.mercadopago.com/init',
                'sandbox_init_point' => 'https://sandbox.mercadopago.com/init-test',
            ]),
        ]);

        $this->postJson("/api/v1/payments/orders/{$foreignOrder->id}/preference")->assertNotFound();
        $this->withHeader('Idempotency-Key', 'owner-pref')->postJson("/api/v1/payments/orders/{$order->id}/preference")
            ->assertOk()
            ->assertJsonPath('data.order_id', 'OWN-ORDER')
            ->assertJsonPath('data.total', 70)
            ->assertJsonPath('data.public_key', 'public');
        $this->withHeader('Idempotency-Key', 'different-http-retry')->postJson("/api/v1/payments/orders/{$order->id}/preference")->assertOk();

        $this->assertDatabaseCount('mercadopago', 1);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => is_string($request->header('X-Idempotency-Key')[0] ?? null)
            && strlen($request->header('X-Idempotency-Key')[0]) === 64);
    }

    public function test_webhook_requires_a_valid_signature_and_verifies_the_remote_payment(): void
    {
        [$otherUser] = $this->signInStore('shared-merchant@example.test');
        MercadoPagoAccount::create([
            'idLog' => $otherUser->id,
            'secretKey' => 'shared-store-access-token',
            'publicKey' => 'shared-public',
            'merchantId' => '986',
        ]);
        [$user, $store] = $this->signInStore('webhook@example.test');
        $order = $this->order($store, 'WEBHOOK-ORDER', 75, 5);
        MercadoPagoAccount::create([
            'idLog' => $user->id,
            'secretKey' => 'store-access-token',
            'publicKey' => 'public',
            'merchantId' => '987',
        ]);
        Cart::create([
            'product' => 1,
            'price' => 75,
            'dom' => $store->createdby,
            'user' => 'webhook-cart',
            'variation' => $store->serial,
            'cant' => 1,
            'status' => 2,
            'orderC' => $order->order,
        ]);
        MercadoPagoPayment::create([
            'orderP' => $order->order,
            'status' => 0,
            'preference' => '',
            'fecha' => now()->format('Y-m-d H:i:s'),
        ]);
        config([
            'services.mercadopago.webhook_secret' => 'webhook-secret',
        ]);
        Http::fake([
            'api.mercadopago.com/v1/payments/12345' => Http::response([
                'external_reference' => $order->order,
                'status' => 'approved',
                'transaction_amount' => 30,
                'collector_id' => 987,
                'currency_id' => 'MXN',
            ]),
            'api.mercadopago.com/v1/payments/12346' => Http::response([
                'external_reference' => $order->order,
                'status' => 'approved',
                'transaction_amount' => 50,
                'collector_id' => 987,
                'currency_id' => 'MXN',
            ]),
        ]);
        $payload = ['type' => 'payment', 'user_id' => 987, 'data' => ['id' => '12345']];

        $this->withHeaders([
            'X-Request-Id' => 'request-one',
            'X-Signature' => 'ts=100,v1=invalid',
        ])->postJson('/api/v1/payments/webhook', $payload)->assertUnauthorized();
        Http::assertNothingSent();

        $this->withHeaders($this->signatureHeaders('12345', 'request-one', '100'))
            ->postJson('/api/v1/payments/webhook', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'ok');
        $payload['data']['id'] = '12346';
        $this->withHeaders($this->signatureHeaders('12346', 'request-two', '101'))
            ->postJson('/api/v1/payments/webhook', $payload)
            ->assertOk();
        $payload['data']['id'] = '12345';
        $this->withHeaders($this->signatureHeaders('12345', 'request-three', '102'))
            ->postJson('/api/v1/payments/webhook', $payload)
            ->assertOk();

        $this->assertDatabaseHas('mercadopago', ['orderP' => $order->order, 'status' => 1]);
        $this->assertDatabaseHas('cart', ['orderC' => $order->order, 'status' => 3]);
        $this->assertDatabaseCount('order_provider_transactions', 2);
        $this->assertEquals(80, (float) OrderPayment::where('order_id', $order->id)->value('amount_paid'));
    }

    public function test_webhook_records_a_late_approved_payment_as_refund_pending(): void
    {
        [$user, $store] = $this->signInStore('webhook-cancel@example.test');
        $order = $this->order($store, 'WEBHOOK-CANCELLED', 60);
        $order->update(['order_state' => PurchaseOrder::STATE_CANCELLED]);
        MercadoPagoAccount::create([
            'idLog' => $user->id,
            'secretKey' => 'store-access-token',
            'publicKey' => 'public',
            'merchantId' => '987',
        ]);
        Cart::create([
            'product' => 1,
            'price' => 60,
            'dom' => $store->createdby,
            'user' => 'webhook-cancel-cart',
            'variation' => $store->serial,
            'cant' => 1,
            'status' => 2,
            'orderC' => $order->order,
        ]);
        MercadoPagoPayment::create([
            'orderP' => $order->order,
            'status' => 0,
            'preference' => '',
            'fecha' => now()->format('Y-m-d H:i:s'),
        ]);
        config([
            'services.mercadopago.webhook_secret' => 'webhook-secret',
        ]);
        Http::fake([
            'api.mercadopago.com/v1/payments/777' => Http::response([
                'external_reference' => $order->order,
                'status' => 'approved',
                'transaction_amount' => 60,
                'collector_id' => 987,
                'currency_id' => 'MXN',
            ]),
        ]);

        $this->withHeaders($this->signatureHeaders('777', 'request-cancelled', '100'))
            ->postJson('/api/v1/payments/webhook', [
                'type' => 'payment',
                'user_id' => 987,
                'data' => ['id' => '777'],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->assertDatabaseHas('mercadopago', ['orderP' => $order->order, 'status' => 1]);
        $this->assertDatabaseHas('order_payments', ['order_id' => $order->id, 'status' => 'refund_pending']);
        $this->assertDatabaseHas('ordencompra', [
            'order' => $order->order,
            'order_state' => PurchaseOrder::STATE_CANCELLED,
        ]);
    }

    public function test_webhook_preserves_a_partial_charge_without_marking_the_order_paid(): void
    {
        [$user, $store] = $this->signInStore('mismatch@example.test');
        $order = $this->order($store, 'MISMATCH-ORDER', 75);
        MercadoPagoAccount::create([
            'idLog' => $user->id,
            'secretKey' => 'store-access-token',
            'publicKey' => 'public',
            'merchantId' => '987',
        ]);
        MercadoPagoPayment::create(['orderP' => $order->order, 'status' => 0, 'preference' => '', 'fecha' => null]);
        config([
            'services.mercadopago.webhook_secret' => 'webhook-secret',
        ]);
        Http::fake([
            'api.mercadopago.com/v1/payments/999' => Http::response([
                'external_reference' => $order->order,
                'status' => 'approved',
                'transaction_amount' => 74,
                'collector_id' => 987,
                'currency_id' => 'MXN',
            ]),
        ]);

        $this->withHeaders($this->signatureHeaders('999', 'request-mismatch', '100'))
            ->postJson('/api/v1/payments/webhook', ['type' => 'payment', 'user_id' => 987, 'data' => ['id' => '999']])
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->assertDatabaseHas('mercadopago', ['orderP' => $order->order, 'status' => 0]);
        $this->assertDatabaseHas('order_provider_transactions', [
            'order_id' => $order->id,
            'provider_payment_id' => '999',
            'amount' => 74,
        ]);
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'status' => 'pending',
            'amount_paid' => 74,
        ]);
    }

    public function test_saving_an_account_verifies_and_persists_the_merchant_identity(): void
    {
        [$user] = $this->signInStore('account@example.test');
        Http::fake([
            'api.mercadopago.com/users/me' => Http::response(['id' => 54321]),
        ]);

        $this->postJson('/api/v1/payments/account', [
            'secret_key' => 'verified-token',
            'public_key' => 'verified-public',
        ])->assertOk();

        $this->assertDatabaseHas('mercadopagocuentas', [
            'idLog' => $user->id,
            'merchantId' => '54321',
            'publicKey' => 'verified-public',
        ]);
    }

    private function order(Store $store, string $reference, float $total, float $shipping = 0): PurchaseOrder
    {
        $order = PurchaseOrder::create([
            'order' => $reference,
            'serial' => $store->serial,
            'session' => 'payment-cart',
            'tel' => '5551112222',
            'nombre' => 'Cliente',
            'lat' => '',
            'long' => '',
            'date' => now()->format('Y-m-d H:i:s'),
            'total' => $total,
            'totEnvio' => $shipping,
        ]);
        OrderPayment::create([
            'order_id' => $order->id,
            'store_id' => $store->id,
            'method' => 'mercado_pago',
            'terms' => 'prepaid',
            'status' => 'pending',
            'currency' => 'MXN',
            'products_amount' => $total,
            'discount_amount' => 0,
            'shipping_amount' => $shipping,
            'extra_amount' => 0,
            'amount_due' => $total + $shipping,
            'amount_paid' => 0,
            'amount_refunded' => 0,
            'frozen_at' => now(),
        ]);

        return $order;
    }

    private function signatureHeaders(string $paymentId, string $requestId, string $timestamp): array
    {
        $manifest = "id:{$paymentId};request-id:{$requestId};ts:{$timestamp};";
        $hash = hash_hmac('sha256', $manifest, 'webhook-secret');

        return ['X-Request-Id' => $requestId, 'X-Signature' => "ts={$timestamp},v1={$hash}"];
    }
}
