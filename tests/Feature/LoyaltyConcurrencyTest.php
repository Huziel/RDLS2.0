<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\LoyaltyConfig;
use App\Models\LoyaltyPoint;
use App\Models\Store;
use Tests\Support\InteractsWithSalesSchema;
use Tests\TestCase;

class LoyaltyConcurrencyTest extends TestCase
{
    use InteractsWithSalesSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesSchema();
    }

    public function test_idempotent_redemption_cannot_overdraw_a_balance(): void
    {
        [, $store] = $this->signInStore('loyalty@example.test', ['pos.use']);
        $client = Client::create(['store_id' => $store->id, 'name' => 'Cliente', 'phone' => '5551002000']);
        LoyaltyConfig::create([
            'store_id' => $store->id,
            'points_per_peso' => 1,
            'pesos_per_point' => 1,
            'minimum_points_to_redeem' => 1,
            'enabled' => true,
        ]);
        LoyaltyPoint::create(['store_id' => $store->id, 'client_id' => $client->id, 'points' => 100]);
        $payload = [
            'client_id' => $client->id,
            'points' => 80,
            'idempotency_key' => 'same-redemption',
        ];

        $this->postJson('/api/v1/pos/loyalty/redeem', $payload)->assertOk()->assertJsonPath('data.remaining', 20);
        $this->postJson('/api/v1/pos/loyalty/redeem', $payload)->assertOk()->assertJsonPath('data.remaining', 20);
        $this->postJson('/api/v1/pos/loyalty/redeem', [...$payload, 'points' => 79])
            ->assertUnprocessable();
        $this->postJson('/api/v1/pos/loyalty/redeem', [...$payload, 'idempotency_key' => 'other-redemption'])
            ->assertUnprocessable();

        $this->assertDatabaseHas('loyalty_points', ['client_id' => $client->id, 'points' => 20]);
        $this->assertDatabaseCount('loyalty_transactions', 1);
    }

    public function test_redemption_rejects_a_client_from_another_store(): void
    {
        [, $store] = $this->signInStore('loyalty-owner@example.test', ['pos.use']);
        $foreignStore = Store::create(['serial' => 'FOREIGN-LOYALTY', 'createdby' => 'loyalty-foreign@example.test']);
        $client = Client::create(['store_id' => $foreignStore->id, 'name' => 'Ajeno', 'phone' => '5559000000']);
        LoyaltyConfig::create([
            'store_id' => $store->id,
            'points_per_peso' => 1,
            'pesos_per_point' => 1,
            'minimum_points_to_redeem' => 1,
            'enabled' => true,
        ]);

        $this->postJson('/api/v1/pos/loyalty/redeem', [
            'client_id' => $client->id,
            'points' => 1,
            'idempotency_key' => 'foreign-client',
        ])->assertNotFound();
    }

    public function test_pos_payment_applies_loyalty_and_remains_idempotent(): void
    {
        [, $store] = $this->signInStore('pos-loyalty@example.test', ['pos.use']);
        $product = $this->createProduct($store->createdby, ['number' => '100'], 2);
        $client = Client::create(['store_id' => $store->id, 'name' => 'Cliente POS', 'phone' => '5557000000']);
        LoyaltyConfig::create([
            'store_id' => $store->id,
            'points_per_peso' => 1,
            'pesos_per_point' => 10,
            'minimum_points_to_redeem' => 10,
            'enabled' => true,
        ]);
        LoyaltyPoint::create(['store_id' => $store->id, 'client_id' => $client->id, 'points' => 100]);

        $orderId = $this->postJson('/api/v1/pos/orders', [
            'nombre' => 'Cliente POS',
            'telefono' => '5557000000',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/pos/orders/{$orderId}/products", [
            'producto_id' => $product->id,
            'cantidad' => 1,
        ])->assertOk();
        $this->postJson("/api/v1/pos/orders/{$orderId}/save", ['extra' => 0])->assertOk();
        $payload = ['tipo_pago' => 'efectivo', 'loyalty_points' => 50];

        $this->postJson("/api/v1/pos/orders/{$orderId}/pay", $payload)
            ->assertOk()
            ->assertJsonPath('data.total', 95)
            ->assertJsonPath('data.descuento', 5)
            ->assertJsonPath('idempotent', false);
        $this->postJson("/api/v1/pos/orders/{$orderId}/pay", $payload)
            ->assertOk()
            ->assertJsonPath('idempotent', true);

        $this->assertDatabaseHas('stock', ['idProd' => $product->id, 'stock' => 1]);
        $this->assertDatabaseHas('loyalty_points', ['client_id' => $client->id, 'points' => 145]);
        $this->assertDatabaseCount('loyalty_transactions', 2);
        $this->assertDatabaseCount('pventageneralhisto', 1);
    }
}
