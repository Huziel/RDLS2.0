<?php

namespace Tests\Feature;

use App\Models\ProductBarcode;
use Spatie\Permission\Models\Role;
use Tests\Support\InteractsWithInventorySchema;
use Tests\TestCase;

class InventoryApiTest extends TestCase
{
    use InteractsWithInventorySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildInventorySchema();
    }

    public function test_product_stock_listing_is_scoped_to_the_authenticated_store(): void
    {
        [, $store] = $this->signInStore('one@example.test', ['products.read']);
        $own = $this->createProduct($store->createdby, ['keyy' => 'Propio'], 7);
        $this->createProduct('two@example.test', ['keyy' => 'Ajeno'], 99);

        $response = $this->getJson('/api/v1/products');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonPath('data.0.stock', 7);
    }

    public function test_stock_update_is_absolute_and_rejects_negative_values(): void
    {
        [, $store] = $this->signInStore('stock@example.test', ['products.update']);
        $product = $this->createProduct($store->createdby, stock: 4);

        $this->putJson("/api/v1/products/{$product->id}", ['stock' => 12])
            ->assertOk()
            ->assertJsonPath('data.stock.cantidad', 12);
        $this->assertDatabaseHas('stock', ['idProd' => $product->id, 'stock' => 12]);

        $this->putJson("/api/v1/products/{$product->id}", ['stock' => -1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stock');
        $this->assertDatabaseHas('stock', ['idProd' => $product->id, 'stock' => 12]);
    }

    public function test_a_store_cannot_update_foreign_product_stock(): void
    {
        $this->signInStore('one@example.test', ['products.update']);
        $foreign = $this->createProduct('two@example.test', stock: 8);

        $this->putJson("/api/v1/products/{$foreign->id}", ['stock' => 1])
            ->assertNotFound();
        $this->assertDatabaseHas('stock', ['idProd' => $foreign->id, 'stock' => 8]);
    }

    public function test_categories_are_distinct_strings_scoped_to_the_store(): void
    {
        [, $store] = $this->signInStore('categories@example.test', ['products.read']);
        $this->createProduct($store->createdby, ['category' => 'Flores']);
        $this->createProduct($store->createdby, ['category' => 'Bebidas']);
        $this->createProduct($store->createdby, ['category' => 'Flores']);
        $this->createProduct('foreign@example.test', ['category' => 'Privada']);

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertExactJson(['data' => ['Bebidas', 'Flores']]);
    }

    public function test_barcode_search_is_active_tenant_scoped_and_returns_stock(): void
    {
        [, $store] = $this->signInStore('barcode@example.test', ['products.read']);
        $own = $this->createProduct($store->createdby, ['keyy' => 'Escaneado'], 3);
        ProductBarcode::create(['idProd' => $own->id, 'code' => '750123']);
        $foreign = $this->createProduct('foreign@example.test');
        ProductBarcode::create(['idProd' => $foreign->id, 'code' => '750123']);

        $this->getJson('/api/v1/products/search-barcode?code=750123')
            ->assertOk()
            ->assertJsonPath('data.id', $own->id)
            ->assertJsonPath('data.stock', 3);

        $own->update(['active' => false]);
        $this->getJson('/api/v1/products/search-barcode?code=750123')->assertNotFound();
    }

    public function test_duplicate_barcode_is_rejected_within_the_same_store(): void
    {
        [, $store] = $this->signInStore('duplicate@example.test', ['products.update']);
        $first = $this->createProduct($store->createdby);
        $second = $this->createProduct($store->createdby);
        ProductBarcode::create(['idProd' => $first->id, 'code' => 'ABC-1']);

        $this->putJson("/api/v1/products/{$second->id}", ['codigo_barras' => 'ABC-1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('codigo_barras');
        $this->assertDatabaseMissing('cbarras', ['idProd' => $second->id]);
    }

    public function test_integer_barcodes_are_normalized_without_accepting_lossy_values(): void
    {
        [, $store] = $this->signInStore('numeric-barcode@example.test', ['products.update']);
        $product = $this->createProduct($store->createdby);

        $this->putJson("/api/v1/products/{$product->id}", ['codigo_barras' => 0])
            ->assertOk()
            ->assertJsonPath('data.codigo_barras', '0');
        $this->assertDatabaseHas('cbarras', ['idProd' => $product->id, 'code' => '0']);

        $this->putJson("/api/v1/products/{$product->id}", ['codigo_barras' => 123456])
            ->assertOk()
            ->assertJsonPath('data.codigo_barras', '123456');

        $this->putJson("/api/v1/products/{$product->id}", ['codigo_barras' => 123.45])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('codigo_barras');
        $this->putJson("/api/v1/products/{$product->id}", ['codigo_barras' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('codigo_barras');
        $this->assertDatabaseHas('cbarras', ['idProd' => $product->id, 'code' => '123456']);
    }

    public function test_product_and_category_endpoints_enforce_permissions(): void
    {
        $this->signInStore('restricted@example.test');

        $this->getJson('/api/v1/products')->assertForbidden();
        $this->getJson('/api/v1/categories')->assertForbidden();
    }

    public function test_dashboard_stats_enforces_its_existing_permission(): void
    {
        $this->signInStore('dashboard-restricted@example.test');

        $this->getJson('/api/v1/dashboard/stats')->assertForbidden();
    }

    public function test_pos_permission_backfill_uses_real_store_ownership(): void
    {
        [$user] = $this->signInStore('legacy-owner@example.test');
        $user->assignRole(Role::findOrCreate('legacy-custom-role', 'web'));
        $migration = require database_path('migrations/2026_09_21_000003_backfill_store_owner_pos_permissions.php');

        $migration->up();
        $user->refresh();

        $this->assertTrue($user->hasRole('store-owner'));
        $this->assertTrue($user->can('pos.use'));
        $this->assertTrue($user->can('pos.history'));
    }
}
