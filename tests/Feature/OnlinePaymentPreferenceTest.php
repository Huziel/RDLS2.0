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

class OnlinePaymentPreferenceTest extends TestCase
{
    use InteractsWithSalesSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesSchema();
    }

    public function test_public_pay_endpoint_creates_a_real_preference_and_persists_it(): void
    {
        [$user, $store] = $this->signInStore('online-pay@example.test');
        MercadoPagoAccount::create([
            'idLog' => $user->id,
            'secretKey' => 'secret',
            'publicKey' => 'public',
            'merchantId' => '456',
        ]);
        $product = $this->createProduct($store->createdby, ['number' => '50'], 2);
        $order = $this->orderWithItem($store, $product->id, 'MP-PREF', 'online-cart', 50);

        Http::fake([
            'api.mercadopago.com/checkout/preferences' => Http::response([
                'id' => 'PREFERENCE-1',
                'init_point' => 'https://sandbox.mercadopago.com/init',
                'sandbox_init_point' => 'https://sandbox.mercadopago.com/sandbox-init',
            ]),
        ]);

        $response = $this->withHeaders(['X-Cart-Token' => 'online-cart', 'Idempotency-Key' => 'mp-pref'])
            ->postJson("/api/v1/stores/{$store->serial}/orders/{$order->order}/pay")
            ->assertOk();

        $response->assertJsonPath('data.order_id', 'MP-PREF')
            ->assertJsonPath('data.preference_id', 'PREFERENCE-1');

        $this->assertDatabaseHas('mercadopago', ['orderP' => 'MP-PREF', 'status' => 0, 'preference' => 'PREFERENCE-1']);

        Http::assertSent(function ($request) use ($order) {
            return str_contains($request->url(), '/checkout/preferences')
                && $request['external_reference'] === $order->order
                && $request['notification_url'] === rtrim(config('app.url'), '/').'/api/v1/payments/webhook'
                && $request['binary_mode'] === true
                && $request['items'][0]['id'] === (string) $order->id
                && (float) $request['items'][0]['unit_price'] === 50.0;
        });
        Http::assertSent(fn ($request) => str_contains($request->url(), '/checkout/preferences')
            && $request->hasHeader('X-Idempotency-Key'));
    }

    public function test_public_pay_is_scoped_by_cart_token_and_store(): void
    {
        [, $store] = $this->signInStore('online-scope@example.test');
        $product = $this->createProduct($store->createdby, ['number' => '30'], 1);
        $order = $this->orderWithItem($store, $product->id, 'MP-SCOPE', 'scope-cart', 30);
        $foreignStore = Store::create(['serial' => 'FOREIGN-ONLINE', 'createdby' => 'foreign@example.test']);

        $this->withHeader('X-Cart-Token', 'scope-cart')
            ->postJson("/api/v1/stores/{$foreignStore->serial}/orders/{$order->order}/pay")
            ->assertNotFound();
        $this->withHeader('X-Cart-Token', 'wrong-cart')
            ->postJson("/api/v1/stores/{$store->serial}/orders/{$order->order}/pay")
            ->assertNotFound();
        $this->postJson("/api/v1/stores/{$store->serial}/orders/{$order->order}/pay")
            ->assertNotFound();
    }

    public function test_public_pay_requires_a_configured_merchant_account(): void
    {
        [, $store] = $this->signInStore('online-noacc@example.test');
        $product = $this->createProduct($store->createdby, ['number' => '10'], 1);
        $order = $this->orderWithItem($store, $product->id, 'MP-NOACC', 'noacc-cart', 10);

        $this->withHeader('X-Cart-Token', 'noacc-cart')
            ->postJson("/api/v1/stores/{$store->serial}/orders/{$order->order}/pay")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Esta tienda debe configurar su cuenta de MercadoPago.');
        Http::assertNothingSent();
    }

    public function test_public_pay_rejects_cancelled_and_paid_orders(): void
    {
        [, $store] = $this->signInStore('online-states@example.test');
        $product = $this->createProduct($store->createdby, ['number' => '10'], 2);
        $paid = $this->orderWithItem($store, $product->id, 'MP-PAID', 'paid-cart', 10);
        $cancelled = $this->orderWithItem($store, $product->id, 'MP-CANCEL', 'cancel-cart', 10);
        PurchaseOrder::where('order', $paid->order)->update(['order_state' => PurchaseOrder::STATE_PAID]);
        OrderPayment::where('order_id', $paid->id)->update(['status' => 'paid', 'amount_paid' => 10, 'frozen_at' => now()]);
        PurchaseOrder::where('order', $cancelled->order)->update(['order_state' => PurchaseOrder::STATE_CANCELLED]);

        $this->withHeader('X-Cart-Token', 'paid-cart')
            ->postJson("/api/v1/stores/{$store->serial}/orders/{$paid->order}/pay")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'La orden ya fue pagada.');
        $this->withHeader('X-Cart-Token', 'cancel-cart')
            ->postJson("/api/v1/stores/{$store->serial}/orders/{$cancelled->order}/pay")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'La orden fue cancelada.');
        Http::assertNothingSent();
    }

    public function test_public_status_reports_pending_then_paid_after_confirmation(): void
    {
        [$user, $store] = $this->signInStore('online-status@example.test');
        $product = $this->createProduct($store->createdby, ['number' => '20'], 1);
        $order = $this->orderWithItem($store, $product->id, 'MP-STATUS', 'status-cart', 20);
        MercadoPagoAccount::create([
            'idLog' => $user->id,
            'secretKey' => 'secret',
            'publicKey' => 'public',
            'merchantId' => '789',
        ]);
        MercadoPagoPayment::create([
            'orderP' => $order->order,
            'status' => 0,
            'preference' => 'P-STATUS',
            'fecha' => now()->format('Y-m-d H:i:s'),
        ]);

        $this->withHeader('X-Cart-Token', 'status-cart')
            ->getJson("/api/v1/stores/{$store->serial}/orders/{$order->order}/status")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        PurchaseOrder::where('order', $order->order)->update(['order_state' => PurchaseOrder::STATE_PAID]);
        OrderPayment::where('order_id', $order->id)->update(['status' => 'paid', 'amount_paid' => 20, 'frozen_at' => now()]);
        MercadoPagoPayment::where('orderP', $order->order)->update(['status' => 1, 'payment_id' => 4242]);

        $this->withHeader('X-Cart-Token', 'status-cart')
            ->getJson("/api/v1/stores/{$store->serial}/orders/{$order->order}/status")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonMissingPath('data.payment.payment_id');

        $this->withHeader('X-Cart-Token', 'status-cart')
            ->getJson("/api/v1/public/orders/{$order->order}")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');
    }

    private function orderWithItem(Store $store, int $productId, string $reference, string $token, float $price): PurchaseOrder
    {
        $order = PurchaseOrder::create([
            'order' => $reference,
            'serial' => $store->serial,
            'session' => $token,
            'tel' => '5551112222',
            'nombre' => 'Cliente',
            'lat' => '',
            'long' => '',
            'date' => now()->format('Y-m-d H:i:s'),
            'total' => $price,
            'totEnvio' => 0,
            'order_state' => PurchaseOrder::STATE_PENDING,
        ]);
        Cart::create([
            'product' => $productId,
            'price' => $price,
            'dom' => $store->createdby,
            'user' => $token,
            'variation' => $store->serial,
            'cant' => 1,
            'status' => 2,
            'orderC' => $reference,
        ]);
        OrderPayment::create([
            'order_id' => $order->id,
            'store_id' => $store->id,
            'method' => 'mercado_pago',
            'terms' => 'prepaid',
            'status' => 'pending',
            'currency' => 'MXN',
            'products_amount' => $price,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'extra_amount' => 0,
            'amount_due' => $price,
            'amount_paid' => 0,
            'amount_refunded' => 0,
        ]);

        return $order;
    }
}
