<?php

namespace Tests\Feature;

use App\Models\ProductAddon;
use Tests\Support\InteractsWithSalesSchema;
use Tests\TestCase;

class PublicCatalogTest extends TestCase
{
    use InteractsWithSalesSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesSchema();
    }

    public function test_public_catalog_is_paginated_and_filters_by_category_and_search(): void
    {
        [, $store] = $this->signInStore('catalog@example.test');
        $this->createProduct($store->createdby, ['keyy' => 'Queso manchego', 'number' => '120', 'category' => 'Frios'], 3);
        $this->createProduct($store->createdby, ['keyy' => 'Queso panela', 'number' => '80', 'category' => 'Frios'], 4);
        $this->createProduct($store->createdby, ['keyy' => 'Bolillo', 'number' => '10', 'category' => 'Pan'], 10);

        $all = $this->getJson("/api/v1/public/stores/{$store->serial}/products?per_page=2")
            ->assertOk()
            ->json();
        $this->assertCount(2, $all['data']);
        $this->assertSame(3, $all['meta']['total']);
        $this->assertSame(2, $all['meta']['per_page']);

        $this->getJson("/api/v1/public/stores/{$store->serial}/products?category=Frios")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.categoria', 'Frios');

        $this->getJson("/api/v1/public/stores/{$store->serial}/products?search=panela")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombre', 'Queso panela');
    }

    public function test_public_catalog_never_exposes_the_store_session_or_owner_fields(): void
    {
        [, $store] = $this->signInStore('catalog-leak@example.test');
        $product = $this->createProduct($store->createdby, ['number' => '60'], 5);
        $addon = ProductAddon::create([
            'idProd' => $product->id,
            'nombre' => 'Adicional',
            'precio' => 7,
            'activo' => true,
        ]);

        $index = $this->getJson("/api/v1/public/stores/{$store->serial}/products")->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('store_session', $index);
        $this->assertArrayNotHasKey('session', $index);
        $this->assertEquals(5, $index['stock']);
        $this->assertSame('60', $index['precio']);

        $show = $this->getJson("/api/v1/public/products/{$product->id}")->assertOk()->json('data');
        $this->assertArrayNotHasKey('store_session', $show);
        $this->assertEquals(5, $show['stock']);
        $this->assertCount(1, $show['aditivos']);
        $this->assertSame('Adicional', $show['aditivos'][0]['nombre']);
        $this->assertEquals(7, $show['aditivos'][0]['precio']);
        $this->assertSame($addon->id, $show['aditivos'][0]['id']);
    }

    public function test_public_catalog_ignores_inactive_products_and_hides_inactive_addons(): void
    {
        [, $store] = $this->signInStore('catalog-active@example.test');
        $visible = $this->createProduct($store->createdby, ['keyy' => 'Visible', 'number' => '10'], 1);
        $this->createProduct($store->createdby, ['keyy' => 'Oculto', 'active' => false], 5);
        ProductAddon::create(['idProd' => $visible->id, 'nombre' => 'Activo', 'precio' => 1, 'activo' => true]);
        ProductAddon::create(['idProd' => $visible->id, 'nombre' => 'Inactivo', 'precio' => 2, 'activo' => false]);

        $this->getJson("/api/v1/public/stores/{$store->serial}/products")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombre', 'Visible');

        $this->getJson("/api/v1/public/products/{$visible->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.aditivos')
            ->assertJsonPath('data.aditivos.0.nombre', 'Activo');
    }
}
