<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Client;
use App\Models\LoyaltyConfig;
use App\Models\LoyaltyPoint;
use App\Models\PurchaseOrder;
use Tests\Support\InteractsWithSalesSchema;
use Tests\TestCase;

class OrderCancellationTest extends TestCase
{
    use InteractsWithSalesSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesSchema();
    }

    public function test_public_cancel_restores_stock_exactly_once_and_is_idempotent(): void
    {
        [, $store] = $this->signInStore('cancel@example.test');
        $product = $this->createProduct($store->createdby, ['number' => '25'], 2);
        $this->cart($product->id, $store->createdby, $store->serial, 'cancel-cart', 2, 50);
        $orderRef = $this->checkout($store->serial, 'cancel-cart');
        $this->assertDatabaseHas('stock', ['idProd' => $product->id, 'stock' => 0]);

        $first = $this->withHeader('X-Cart-Token', 'cancel-cart')
            ->postJson("/api/v1/stores/{$store->serial}/orders/{$orderRef}/cancel")
            ->assertOk()
            ->json('data');
        $this->assertSame('cancelled', $first['status']);
        $this->assertFalse($first['idempotent']);
        $this->assertTrue($first['restocked']);
        $this->assertDatabaseHas('stock', ['idProd' => $product->id, 'stock' => 2]);
        $this->assertDatabaseHas('ordencompra', [
            'order' => $orderRef,
            'order_state' => PurchaseOrder::STATE_CANCELLED,
            'restock_key' => hash('sha256', 'restock:'.$orderRef),
        ]);

        $second = $this->withHeader('X-Cart-Token', 'cancel-cart')
            ->postJson("/api/v1/stores/{$store->serial}/orders/{$orderRef}/cancel")
            ->assertOk()
            ->json('data');
        $this->assertTrue($second['idempotent']);
        $this->assertFalse($second['restocked']);
        $this->assertDatabaseHas('stock', ['idProd' => $product->id, 'stock' => 2]);
    }

    public function test_public_cancel_requires_the_cart_token_and_same_store(): void
    {
        [, $store] = $this->signInStore('cancel-private@example.test');
        $product = $this->createProduct($store->createdby, ['number' => '10'], 1);
        $this->cart($product->id, $store->createdby, $store->serial, 'owner-cart', 1, 10);
        $orderRef = $this->checkout($store->serial, 'owner-cart');

        $this->flushHeaders()
            ->postJson("/api/v1/stores/{$store->serial}/orders/{$orderRef}/cancel")
            ->assertNotFound();
        $this->withHeader('X-Cart-Token', 'other-cart')
            ->postJson("/api/v1/stores/{$store->serial}/orders/{$orderRef}/cancel")
            ->assertNotFound();
        $this->assertDatabaseHas('stock', ['idProd' => $product->id, 'stock' => 0]);
    }

    public function test_public_cancel_rejects_a_paid_order(): void
    {
        [, $store] = $this->signInStore('cancel-paid@example.test');
        $product = $this->createProduct($store->createdby, ['number' => '10'], 1);
        $this->cart($product->id, $store->createdby, $store->serial, 'paid-cart', 1, 10);
        $orderRef = $this->checkout($store->serial, 'paid-cart');
        PurchaseOrder::where('order', $orderRef)->update(['order_state' => PurchaseOrder::STATE_PAID]);

        $this->withHeader('X-Cart-Token', 'paid-cart')
            ->postJson("/api/v1/stores/{$store->serial}/orders/{$orderRef}/cancel")
            ->assertUnprocessable();
        $this->assertDatabaseHas('ordencompra', ['order' => $orderRef, 'order_state' => PurchaseOrder::STATE_PAID]);
    }

    public function test_admin_cancel_flags_a_manual_refund_for_paid_orders(): void
    {
        [, $store] = $this->signInStore('admin-cancel@example.test');
        $product = $this->createProduct($store->createdby, ['number' => '30'], 1);
        $this->cart($product->id, $store->createdby, $store->serial, 'admin-cart', 1, 30);
        $orderRef = $this->checkout($store->serial, 'admin-cart');
        $orderId = PurchaseOrder::where('order', $orderRef)->value('id');
        PurchaseOrder::where('order', $orderRef)->update(['order_state' => PurchaseOrder::STATE_PAID]);

        $result = $this->postJson("/api/v1/orders/{$orderId}/cancel")
            ->assertOk()
            ->json('data');
        $this->assertTrue($result['refund_required']);
        $this->assertDatabaseHas('stock', ['idProd' => $product->id, 'stock' => 1]);
    }

    public function test_cancellation_earns_and_earn_reverse_keeps_balance_stable(): void
    {
        [, $store] = $this->signInStore('cancel-loyalty@example.test');
        $client = Client::create(['store_id' => $store->id, 'name' => 'Leal', 'phone' => '5558009000']);
        LoyaltyConfig::create([
            'store_id' => $store->id,
            'points_per_peso' => 1,
            'pesos_per_point' => 1,
            'minimum_points_to_redeem' => 1,
            'enabled' => true,
        ]);
        $product = $this->createProduct($store->createdby, ['number' => '15'], 1);
        $this->cart($product->id, $store->createdby, $store->serial, 'loyal-cart', 1, 15);
        $orderRef = $this->checkout($store->serial, 'loyal-cart', '5558009000');
        $this->assertDatabaseHas('loyalty_points', ['store_id' => $store->id, 'client_id' => $client->id, 'points' => 15]);

        $this->withHeader('X-Cart-Token', 'loyal-cart')
            ->postJson("/api/v1/stores/{$store->serial}/orders/{$orderRef}/cancel")
            ->assertOk();

        $this->assertDatabaseHas('loyalty_points', ['store_id' => $store->id, 'client_id' => $client->id, 'points' => 0]);
        $this->assertDatabaseHas('loyalty_transactions', [
            'store_id' => $store->id,
            'client_id' => $client->id,
            'type' => 'earn_reverse',
            'reference' => "earn_reverse:{$orderRef}",
            'points' => -15,
        ]);
    }

    public function test_cancellation_reverses_a_redemption_without_going_negative(): void
    {
        [, $store] = $this->signInStore('cancel-redeem@example.test');
        $client = Client::create(['store_id' => $store->id, 'name' => 'Canjes', 'phone' => '5551005000']);
        LoyaltyPoint::create(['store_id' => $store->id, 'client_id' => $client->id, 'points' => 100]);
        $order = PurchaseOrder::create([
            'order' => 'REDEEM-REV',
            'serial' => $store->serial,
            'session' => 'redeem-cart',
            'tel' => '5551005000',
            'nombre' => 'Canjes',
            'lat' => '',
            'long' => '',
            'date' => now()->format('Y-m-d H:i:s'),
            'total' => 95,
            'totEnvio' => 0,
            'checkout_key' => hash('sha256', 'redeem-flow'),
            'loyalty_discount' => 5,
            'order_state' => PurchaseOrder::STATE_PENDING,
        ]);
        LoyaltyPoint::redeemPoints($store->id, $client->id, 50, 'checkout:'.hash('sha256', 'redeem-flow'), 'checkout_redeem');
        $this->assertDatabaseHas('loyalty_points', ['client_id' => $client->id, 'points' => 50]);

        $this->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.reversed', true);

        $this->assertDatabaseHas('loyalty_points', ['client_id' => $client->id, 'points' => 100]);
        $this->assertDatabaseHas('loyalty_transactions', [
            'store_id' => $store->id,
            'client_id' => $client->id,
            'type' => 'redeem_reverse',
            'reference' => 'checkout:'.hash('sha256', 'redeem-flow'),
            'points' => 50,
        ]);
    }

    public function test_repeated_admin_cancel_never_restores_stock_twice(): void
    {
        [, $store] = $this->signInStore('cancel-twice@example.test');
        $product = $this->createProduct($store->createdby, ['number' => '20'], 3);
        $this->cart($product->id, $store->createdby, $store->serial, 'twice-cart', 3, 60);
        $orderRef = $this->checkout($store->serial, 'twice-cart');
        $orderId = PurchaseOrder::where('order', $orderRef)->value('id');

        $this->postJson("/api/v1/orders/{$orderId}/cancel")->assertOk();
        $second = $this->postJson("/api/v1/orders/{$orderId}/cancel")->assertOk()->json('data');
        $this->assertTrue($second['idempotent']);
        $this->assertDatabaseHas('stock', ['idProd' => $product->id, 'stock' => 3]);
        $this->assertDatabaseCount('ordencompra', 1);
    }

    private function checkout(string $serial, string $token, string $phone = '5551112222'): string
    {
        return $this->withHeader('X-Cart-Token', $token)
            ->postJson("/api/v1/stores/{$serial}/checkout", [
                'nombre' => 'Cliente',
                'telefono' => $phone,
                'tipo_envio' => 'pickup',
            ])
            ->assertCreated()
            ->json('data.order_id');
    }

    private function cart(int $productId, string $owner, string $serial, string $token, int $quantity, float $price): Cart
    {
        return Cart::create([
            'product' => $productId,
            'price' => $price,
            'dom' => $owner,
            'user' => $token,
            'variation' => $serial,
            'cant' => $quantity,
            'status' => 0,
        ]);
    }
}
