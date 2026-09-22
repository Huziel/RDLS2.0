<?php

namespace Tests\Feature;

use App\Models\PosOrder;
use App\Models\PosOrderDetail;
use App\Models\PosOrderHistory;
use Tests\Support\InteractsWithInventorySchema;
use Tests\TestCase;

class PosInventoryTest extends TestCase
{
    use InteractsWithInventorySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildInventorySchema();
    }

    public function test_pos_rejects_products_from_another_store(): void
    {
        [, $store] = $this->signInStore('pos@example.test', ['pos.use']);
        $order = $this->createOrder($store->createdby, 0);
        $foreign = $this->createProduct('foreign@example.test');

        $this->postJson("/api/v1/pos/orders/{$order->id}/products", [
            'producto_id' => $foreign->id,
            'cantidad' => 1,
        ])->assertNotFound();
        $this->assertDatabaseCount('pventageneraldetalle', 0);
    }

    public function test_pos_payment_rejects_insufficient_stock_without_partial_writes(): void
    {
        [, $store] = $this->signInStore('insufficient@example.test', ['pos.use']);
        $product = $this->createProduct($store->createdby, ['keyy' => 'Limitado'], 2);
        $order = $this->createOrder($store->createdby, 1);
        $this->createDetail($order->id, $product->id, 3);

        $this->postJson("/api/v1/pos/orders/{$order->id}/pay", ['tipo_pago' => 'efectivo'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stock');

        $this->assertDatabaseHas('stock', ['idProd' => $product->id, 'stock' => 2]);
        $this->assertDatabaseHas('pventageneral', ['id' => $order->id, 'estado' => 1]);
        $this->assertDatabaseCount('pventageneralhisto', 0);
        $this->assertDatabaseCount('pventageneraldetallehisto', 0);
    }

    public function test_pos_payment_rejects_a_product_without_a_stock_balance(): void
    {
        [, $store] = $this->signInStore('missing-stock@example.test', ['pos.use']);
        $product = $this->createProduct($store->createdby, ['keyy' => 'Sin saldo'], null);
        $order = $this->createOrder($store->createdby, 1);
        $this->createDetail($order->id, $product->id, 1);

        $this->postJson("/api/v1/pos/orders/{$order->id}/pay", ['tipo_pago' => 'efectivo'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stock');

        $this->assertDatabaseHas('pventageneral', ['id' => $order->id, 'estado' => 1]);
        $this->assertDatabaseCount('pventageneralhisto', 0);
    }

    public function test_pos_cannot_save_an_empty_order(): void
    {
        [, $store] = $this->signInStore('empty@example.test', ['pos.use']);
        $order = $this->createOrder($store->createdby, 0);

        $this->postJson("/api/v1/pos/orders/{$order->id}/save", ['extra' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order');
        $this->assertDatabaseHas('pventageneral', ['id' => $order->id, 'estado' => 0]);
    }

    public function test_pos_payment_decrements_stock_once_and_creates_history(): void
    {
        [, $store] = $this->signInStore('payment@example.test', ['pos.use']);
        $product = $this->createProduct($store->createdby, ['keyy' => 'Disponible'], 5);
        $order = $this->createOrder($store->createdby, 1);
        $this->createDetail($order->id, $product->id, 2);

        $this->postJson("/api/v1/pos/orders/{$order->id}/pay", ['tipo_pago' => 'tarjeta'])
            ->assertOk();

        $this->assertDatabaseHas('stock', ['idProd' => $product->id, 'stock' => 3]);
        $this->assertDatabaseHas('pventageneral', ['id' => $order->id, 'estado' => 2, 'tipoPago' => 2]);
        $this->assertDatabaseCount('pventageneralhisto', 1);
        $this->assertDatabaseCount('pventageneraldetallehisto', 1);

        $this->postJson("/api/v1/pos/orders/{$order->id}/pay", ['tipo_pago' => 'tarjeta'])
            ->assertOk()
            ->assertJsonPath('idempotent', true);
        $this->assertDatabaseHas('stock', ['idProd' => $product->id, 'stock' => 3]);
        $this->assertDatabaseCount('pventageneralhisto', 1);
    }

    public function test_pos_routes_enforce_existing_permissions(): void
    {
        [, $store] = $this->signInStore('restricted-pos@example.test');
        $order = $this->createOrder($store->createdby, 1);

        $this->getJson('/api/v1/pos/orders')->assertForbidden();
        $this->postJson("/api/v1/pos/orders/{$order->id}/pay", ['tipo_pago' => 'efectivo'])
            ->assertForbidden();
        $this->getJson('/api/v1/pos/history')->assertForbidden();
    }

    public function test_pos_use_does_not_grant_sales_history_access(): void
    {
        $this->signInStore('operator@example.test', ['pos.use']);

        $this->getJson('/api/v1/pos/orders')->assertOk();
        $this->getJson('/api/v1/pos/history')->assertForbidden();
    }

    public function test_pos_use_can_read_the_catalog_without_product_administration_permission(): void
    {
        [, $store] = $this->signInStore('pos-catalog@example.test', ['pos.use']);
        $product = $this->createProduct($store->createdby, ['keyy' => 'Producto POS'], 3);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.stock', 3);
    }

    public function test_pos_history_filters_by_date_payment_and_store(): void
    {
        [, $store] = $this->signInStore('history@example.test', ['pos.history']);
        PosOrderHistory::create([
            'noOrder' => 'cash-in-range',
            'nombre' => 'Cliente',
            'fecha' => '2026-09-10 10:00:00',
            'estado' => 2,
            'total' => 30,
            'extra' => 0,
            'descuento' => 0,
            'tipoPago' => 1,
            'creator' => $store->createdby,
        ]);
        PosOrderHistory::create([
            'noOrder' => 'card-in-range',
            'nombre' => 'Cliente',
            'fecha' => '2026-09-11 10:00:00',
            'estado' => 2,
            'total' => 50,
            'extra' => 0,
            'descuento' => 0,
            'tipoPago' => 2,
            'creator' => $store->createdby,
        ]);
        PosOrderHistory::create([
            'noOrder' => 'foreign',
            'nombre' => 'Ajeno',
            'fecha' => '2026-09-10 10:00:00',
            'estado' => 2,
            'total' => 1000,
            'extra' => 0,
            'descuento' => 0,
            'tipoPago' => 1,
            'creator' => 'foreign@example.test',
        ]);

        $this->getJson('/api/v1/pos/history?from=2026-09-10&to=2026-09-10&payment=efectivo')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.noOrder', 'cash-in-range')
            ->assertJsonPath('stats.count', 1)
            ->assertJsonPath('stats.total', 30)
            ->assertJsonPath('stats.cash', 1)
            ->assertJsonPath('stats.card', 0);
    }

    private function createOrder(string $owner, int $state): PosOrder
    {
        return PosOrder::create([
            'noOrder' => '20260921000000123',
            'nombre' => 'Venta de prueba',
            'fecha' => '2026-09-21 00:00:00',
            'estado' => $state,
            'total' => 200,
            'extra' => 0,
            'descuento' => 0,
            'tipoPago' => 0,
            'creator' => $owner,
        ]);
    }

    private function createDetail(int $orderId, int $productId, int $quantity): PosOrderDetail
    {
        return PosOrderDetail::create([
            'idPventaGeneral' => $orderId,
            'productoId' => $productId,
            'cantidad' => $quantity,
            'nameProd' => 'Producto',
            'precioBruto' => 100,
            'precioNeto' => 100 * $quantity,
        ]);
    }
}
