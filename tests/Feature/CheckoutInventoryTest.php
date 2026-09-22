<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartAddon;
use App\Models\Client;
use App\Models\LoyaltyConfig;
use App\Models\LoyaltyPoint;
use App\Models\ProductAddon;
use App\Models\StoreFeature;
use Tests\Support\InteractsWithSalesSchema;
use Tests\TestCase;

class CheckoutInventoryTest extends TestCase
{
    use InteractsWithSalesSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesSchema();
    }

    public function test_checkout_consumes_stock_and_is_idempotent(): void
    {
        [, $store] = $this->signInStore('checkout@example.test');
        $product = $this->createProduct($store->createdby, ['number' => '25'], 2);
        $this->cart($product->id, $store->createdby, $store->serial, 'cart-checkout', 1, 25);
        $payload = [
            'nombre' => 'Cliente',
            'telefono' => '5551112222',
            'tipo_envio' => 'pickup',
        ];

        $idempotencyKey = str_repeat('k', 100);
        $first = $this->withHeaders([
            'X-Cart-Token' => 'cart-checkout',
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/v1/stores/{$store->serial}/checkout", $payload);

        $first->assertCreated()
            ->assertJsonPath('data.total', 25)
            ->assertJsonPath('data.idempotent', false);
        $this->assertDatabaseHas('stock', ['idProd' => $product->id, 'stock' => 1]);
        $this->assertDatabaseHas('cart', ['user' => 'cart-checkout', 'status' => 2]);
        $cartId = Cart::where('user', 'cart-checkout')->value('id');
        $this->putJson("/api/v1/stores/{$store->serial}/cart/{$cartId}", ['quantity' => 2])
            ->assertNotFound();

        $second = $this->withHeaders([
            'X-Cart-Token' => 'cart-checkout',
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/v1/stores/{$store->serial}/checkout", $payload);

        $second->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.idempotent', true);
        $this->assertDatabaseHas('stock', ['idProd' => $product->id, 'stock' => 1]);
        $this->assertDatabaseCount('ordencompra', 1);

        $this->flushHeaders()->withHeader('X-Cart-Token', 'empty-cart')
            ->postJson("/api/v1/stores/{$store->serial}/checkout", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cart');

        $this->cart($product->id, $store->createdby, $store->serial, 'cart-checkout', 1, 25);
        $this->withHeaders(['X-Cart-Token' => 'cart-checkout', 'Idempotency-Key' => $idempotencyKey])
            ->postJson("/api/v1/stores/{$store->serial}/checkout", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $this->cart($product->id, $store->createdby, $store->serial, 'other-cart', 1, 25);
        $this->withHeaders(['X-Cart-Token' => 'other-cart', 'Idempotency-Key' => $idempotencyKey])
            ->postJson("/api/v1/stores/{$store->serial}/checkout", $payload)
            ->assertCreated();
        $this->assertDatabaseCount('ordencompra', 2);
        $this->assertDatabaseHas('stock', ['idProd' => $product->id, 'stock' => 0]);
    }

    public function test_checkout_rejects_insufficient_stock_without_partial_writes(): void
    {
        [, $store] = $this->signInStore('no-stock@example.test');
        $product = $this->createProduct($store->createdby, ['number' => '25'], 0);
        $this->cart($product->id, $store->createdby, $store->serial, 'cart-empty-stock', 1, 25);

        $this->withHeader('X-Cart-Token', 'cart-empty-stock')
            ->postJson("/api/v1/stores/{$store->serial}/checkout", [
                'nombre' => 'Cliente',
                'telefono' => '5551112222',
                'tipo_envio' => 'pickup',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stock');

        $this->assertDatabaseCount('ordencompra', 0);
        $this->assertDatabaseHas('cart', ['user' => 'cart-empty-stock', 'status' => 0]);
        $this->assertDatabaseHas('stock', ['idProd' => $product->id, 'stock' => 0]);
    }

    public function test_checkout_rejects_a_product_from_another_store(): void
    {
        [, $store] = $this->signInStore('checkout-owner@example.test');
        $foreign = $this->createProduct('foreign@example.test', ['number' => '30'], 4);
        $this->cart($foreign->id, $store->createdby, $store->serial, 'cart-foreign', 1, 30);

        $this->withHeader('X-Cart-Token', 'cart-foreign')
            ->postJson("/api/v1/stores/{$store->serial}/checkout", [
                'nombre' => 'Cliente',
                'telefono' => '5551112222',
                'tipo_envio' => 'pickup',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stock');

        $this->assertDatabaseCount('ordencompra', 0);
        $this->assertDatabaseHas('stock', ['idProd' => $foreign->id, 'stock' => 4]);
    }

    public function test_checkout_rejects_an_addon_that_is_no_longer_available(): void
    {
        [, $store] = $this->signInStore('checkout-addon@example.test');
        $product = $this->createProduct($store->createdby, ['number' => '25'], 2);
        $cart = $this->cart($product->id, $store->createdby, $store->serial, 'cart-addon', 1, 30);
        $addon = ProductAddon::create([
            'idProd' => $product->id,
            'nombre' => 'Extra',
            'precio' => 5,
            'activo' => true,
        ]);
        CartAddon::create([
            'noOrder' => $cart->id,
            'idAditivo' => $addon->id,
            'session' => 'cart-addon',
        ]);
        $addon->update(['activo' => false]);

        $this->withHeader('X-Cart-Token', 'cart-addon')
            ->postJson("/api/v1/stores/{$store->serial}/checkout", [
                'nombre' => 'Cliente',
                'telefono' => '5551112222',
                'tipo_envio' => 'pickup',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cart');

        $this->assertDatabaseCount('ordencompra', 0);
        $this->assertDatabaseHas('stock', ['idProd' => $product->id, 'stock' => 2]);
    }

    public function test_checkout_earns_loyalty_once_but_prohibits_public_redemption(): void
    {
        [, $store] = $this->signInStore('checkout-loyalty@example.test');
        $product = $this->createProduct($store->createdby, ['number' => '15'], 2);
        $this->cart($product->id, $store->createdby, $store->serial, 'cart-loyalty', 1, 15);
        $client = Client::create([
            'store_id' => $store->id,
            'name' => 'Cliente leal',
            'phone' => '5553334444',
        ]);
        LoyaltyConfig::create([
            'store_id' => $store->id,
            'points_per_peso' => 1,
            'pesos_per_point' => 1,
            'minimum_points_to_redeem' => 10,
            'enabled' => true,
        ]);
        LoyaltyPoint::create(['store_id' => $store->id, 'client_id' => $client->id, 'points' => 20]);
        $headers = ['X-Cart-Token' => 'cart-loyalty', 'Idempotency-Key' => 'loyalty-order'];
        $payload = [
            'nombre' => 'Cliente leal',
            'telefono' => '5553334444',
            'tipo_envio' => 'pickup',
        ];

        $this->withHeaders($headers)
            ->postJson("/api/v1/stores/{$store->serial}/checkout", [...$payload, 'loyalty_points' => 10])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('loyalty_points');

        $this->withHeaders($headers)
            ->postJson("/api/v1/stores/{$store->serial}/checkout", $payload)
            ->assertCreated()
            ->assertJsonPath('data.total', 15)
            ->assertJsonPath('data.loyalty_discount', 0);
        $this->withHeaders($headers)
            ->postJson("/api/v1/stores/{$store->serial}/checkout", $payload)
            ->assertOk()
            ->assertJsonPath('data.idempotent', true);

        $this->assertDatabaseHas('loyalty_points', [
            'store_id' => $store->id,
            'client_id' => $client->id,
            'points' => 35,
        ]);
        $this->assertDatabaseCount('loyalty_transactions', 1);
        $this->assertDatabaseCount('ordencompra', 1);
    }

    public function test_checkout_enforces_delivery_features_and_uses_the_highest_local_rate(): void
    {
        [, $store] = $this->signInStore('shipping@example.test');
        $store->update(['color' => '5|10|20']);
        StoreFeature::create(['idTienda' => $store->id, 'idComponent' => 1, 'active' => true]);
        $product = $this->createProduct($store->createdby, ['number' => '10'], 2);
        $this->cart($product->id, $store->createdby, $store->serial, 'pickup-disabled', 1, 10);

        $this->withHeader('X-Cart-Token', 'pickup-disabled')
            ->postJson("/api/v1/stores/{$store->serial}/checkout", [
                'nombre' => 'Cliente',
                'telefono' => '5551112222',
                'tipo_envio' => 'pickup',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tipo_envio');

        $this->withHeader('X-Cart-Token', 'pickup-disabled')
            ->postJson("/api/v1/stores/{$store->serial}/checkout", [
                'nombre' => 'Cliente',
                'telefono' => '5551112222',
                'tipo_envio' => 'shipping',
                'direccion' => 'Direccion distante',
                'lat' => 0,
                'lng' => 0,
            ])
            ->assertCreated()
            ->assertJsonPath('data.shipping', 20);
    }

    public function test_public_order_detail_requires_the_cart_token_that_created_it(): void
    {
        [, $store] = $this->signInStore('private-order@example.test');
        $product = $this->createProduct($store->createdby, ['number' => '10'], 1);
        $this->cart($product->id, $store->createdby, $store->serial, 'private-cart', 1, 10);
        $order = $this->withHeader('X-Cart-Token', 'private-cart')
            ->postJson("/api/v1/stores/{$store->serial}/checkout", [
                'nombre' => 'Cliente',
                'telefono' => '5551112222',
                'tipo_envio' => 'pickup',
            ])
            ->assertCreated()
            ->json('data.order_id');

        $this->withHeader('X-Cart-Token', 'other-cart')
            ->getJson("/api/v1/public/orders/{$order}")
            ->assertNotFound();
        $this->withHeader('X-Cart-Token', 'private-cart')
            ->getJson("/api/v1/public/orders/{$order}")
            ->assertOk()
            ->assertJsonPath('data.order', $order);
    }

    private function cart(
        int $productId,
        string $owner,
        string $serial,
        string $token,
        int $quantity,
        float $price,
    ): Cart {
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
