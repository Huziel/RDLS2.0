<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartAddon;
use App\Models\MercadoPagoAccount;
use App\Models\OrderPayment;
use App\Models\ProductAddon;
use App\Models\PurchaseOrder;
use App\Models\Store;
use App\Models\StoreColor;
use App\Models\StoreExtra;
use App\Models\StoreFeature;
use App\Models\StorePassword;
use App\Models\StorePaymentSetting;
use App\Services\OrderNotificationHtml;
use App\Services\StorePaymentMethods;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\InteractsWithSalesSchema;
use Tests\TestCase;

class Fase8bSmallTest extends TestCase
{
    use InteractsWithSalesSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesSchema();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_protected_catalog_rejects_direct_access_and_accepts_only_its_capability(): void
    {
        [, $store] = $this->signInStore('catalog-gate@example.test');
        $product = $this->createProduct($store->createdby);
        ProductAddon::create(['idProd' => $product->id, 'nombre' => 'Extra', 'precio' => 2, 'activo' => true]);
        $this->protect($store, 'secret-one');

        $this->getJson("/api/v1/public/stores/{$store->serial}/products")->assertUnauthorized();
        $this->withHeader('X-Store-Capability', 'not-encrypted')
            ->getJson("/api/v1/public/stores/{$store->serial}/products")
            ->assertForbidden();
        $this->postJson("/api/v1/catalog/{$store->id}/verify-password", [
            'store_id' => $store->id,
            'password' => 'secret-one',
        ])->assertNotFound();

        $capability = $this->unlock($store, 'secret-one');
        $this->withHeader('X-Store-Capability', $capability)
            ->getJson("/api/v1/public/stores/{$store->serial}/products")
            ->assertOk();
        $this->withHeader('X-Store-Capability', $capability)
            ->getJson("/api/v1/public/stores/{$store->serial}/products/{$product->id}")
            ->assertOk();
        $this->withHeader('X-Store-Capability', $capability)
            ->getJson("/api/v1/public/stores/{$store->serial}/products/{$product->id}/addons")
            ->assertOk();

        [, $other] = $this->signInStore('catalog-other@example.test');
        $this->protect($other, 'secret-two');
        $this->withHeader('X-Store-Capability', $capability)
            ->getJson("/api/v1/public/stores/{$other->serial}/products")
            ->assertForbidden();
    }

    public function test_capability_expires_and_password_update_or_recreation_revokes_it(): void
    {
        Carbon::setTestNow('2026-09-23 10:00:00');
        config(['store_catalog.capability_ttl_minutes' => 30]);
        [, $store] = $this->signInStore('catalog-revoke@example.test');
        $this->protect($store, 'first-pass');

        $expired = $this->unlock($store, 'first-pass');
        Carbon::setTestNow('2026-09-23 10:30:00');
        $this->withHeader('X-Store-Capability', $expired)
            ->getJson("/api/v1/public/stores/{$store->serial}/availability")
            ->assertForbidden();

        Carbon::setTestNow('2026-09-23 11:00:00');
        $updated = $this->unlock($store, 'first-pass');
        $this->putJson('/api/v1/store/catalog-password', ['catalog_pass' => 'second-pass'])->assertOk();
        $this->withHeader('X-Store-Capability', $updated)
            ->getJson("/api/v1/public/stores/{$store->serial}")
            ->assertForbidden();

        $recreated = $this->unlock($store, 'second-pass');
        StorePassword::where('idTienda', $store->id)->delete();
        $this->protect($store, 'third-pass');
        $this->withHeader('X-Store-Capability', $recreated)
            ->getJson("/api/v1/public/stores/{$store->serial}")
            ->assertForbidden();
    }

    public function test_unprotected_catalog_remains_public_and_locked_theme_is_minimal(): void
    {
        [, $open] = $this->signInStore('catalog-open@example.test');
        $product = $this->createProduct($open->createdby);
        $this->getJson("/api/v1/public/stores/{$open->serial}/products")->assertOk();
        $this->getJson("/api/v1/public/stores/{$open->serial}/products/{$product->id}")->assertOk();

        [, $locked] = $this->signInStore('catalog-theme@example.test');
        StoreExtra::where('idTienda', $locked->id)->update([
            'nombreTienda' => 'Nombre publico',
            'sections' => '[{"private":true}]',
        ]);
        StoreColor::create([
            'idStore' => $locked->id,
            'coloruno' => '#111111', 'colordos' => '#222222', 'colortres' => '#333333',
            'colorcuatro' => '#444444', 'colorcinco' => '#555555',
        ]);
        $this->protect($locked, 'theme-pass');

        $minimal = $this->getJson("/api/v1/public/stores/{$locked->serial}/theme")
            ->assertOk()
            ->assertJsonPath('data.has_password', true)
            ->assertJsonPath('data.catalog_locked', true)
            ->assertJsonPath('data.name', 'Nombre publico')
            ->json('data');
        $this->assertSame(['has_password', 'catalog_locked', 'name', 'colors'], array_keys($minimal));

        $capability = $this->unlock($locked, 'theme-pass');
        $this->withHeader('X-Store-Capability', $capability)
            ->getJson("/api/v1/public/stores/{$locked->serial}/theme")
            ->assertOk()
            ->assertJsonPath('data.catalog_locked', false)
            ->assertJsonPath('data.payment_methods.cash', true)
            ->assertJsonPath('data.sections.0.private', true);
    }

    public function test_product_addon_cart_and_checkout_are_all_gated_for_a_protected_store(): void
    {
        [, $store] = $this->signInStore('catalog-all-routes@example.test');
        $product = $this->createProduct($store->createdby, ['number' => '10'], 3);
        ProductAddon::create(['idProd' => $product->id, 'nombre' => 'Extra', 'precio' => 2, 'activo' => true]);
        $this->protect($store, 'route-pass');
        $cart = Cart::create([
            'product' => $product->id, 'price' => 10, 'dom' => $store->createdby,
            'user' => 'gated-cart', 'variation' => $store->serial, 'cant' => 1, 'status' => 0,
        ]);

        $this->getJson("/api/v1/public/stores/{$store->serial}/products/{$product->id}")->assertUnauthorized();
        $this->getJson("/api/v1/public/stores/{$store->serial}/products/{$product->id}/addons")->assertUnauthorized();
        $this->withHeader('X-Cart-Token', 'gated-cart')
            ->getJson("/api/v1/stores/{$store->serial}/cart")->assertUnauthorized();
        $this->withHeader('X-Cart-Token', 'gated-cart')
            ->putJson("/api/v1/stores/{$store->serial}/cart/{$cart->id}", ['quantity' => 2])->assertUnauthorized();
        $this->withHeaders(['X-Cart-Token' => 'gated-cart', 'Idempotency-Key' => 'gated-checkout'])
            ->postJson("/api/v1/stores/{$store->serial}/checkout", $this->checkoutPayload('cash'))
            ->assertUnauthorized();
        $this->assertDatabaseCount('ordencompra', 0);

        $capability = $this->unlock($store, 'route-pass');
        $this->withHeaders([
            'X-Cart-Token' => 'gated-cart',
            'Idempotency-Key' => 'gated-checkout',
            'X-Store-Capability' => $capability,
        ])->postJson("/api/v1/stores/{$store->serial}/checkout", $this->checkoutPayload('cash'))
            ->assertCreated();
    }

    public function test_payment_method_resolver_requires_complete_credentials_and_cod_local_flag(): void
    {
        [$owner, $store] = $this->signInStore('methods@example.test');
        $resolver = app(StorePaymentMethods::class);

        $methods = $resolver->resolve($store);
        $this->assertTrue($methods['cash']);
        $this->assertTrue($methods['bank_transfer']);
        $this->assertFalse($methods['mercado_pago']);
        $this->assertFalse($methods['cash_on_delivery']);

        $extra = StoreExtra::where('idTienda', $store->id)->firstOrFail();
        $extra->update(['transf1' => ' ', 'nameBanc2' => 'Bank 2', 'namePrope2' => 'Owner 2', 'transf2' => null]);
        $this->assertFalse($resolver->resolve($store)['bank_transfer']);
        $extra->update(['transf2' => '222222']);
        $this->assertTrue($resolver->resolve($store)['bank_transfer']);

        MercadoPagoAccount::create([
            'idLog' => $owner->id, 'merchantId' => 'merchant', 'secretKey' => 'secret', 'publicKey' => ' ',
        ]);
        $this->assertFalse($resolver->resolve($store)['mercado_pago']);
        MercadoPagoAccount::where('idLog', $owner->id)->update(['publicKey' => 'public']);
        $this->assertTrue($resolver->resolve($store)['mercado_pago']);

        StorePaymentSetting::create(['store_id' => $store->id, 'cash_on_delivery_enabled' => true]);
        $this->assertTrue($resolver->resolve($store)['cash_on_delivery']);
        StoreFeature::create(['idTienda' => $store->id, 'idComponent' => 2, 'active' => true]);
        $this->assertFalse($resolver->resolve($store)['cash_on_delivery']);
        StoreFeature::create(['idTienda' => $store->id, 'idComponent' => 1, 'active' => true]);
        $this->assertTrue($resolver->resolve($store)['cash_on_delivery']);
    }

    public function test_disabled_payment_method_fails_before_inventory_order_or_cart_mutation(): void
    {
        [, $store] = $this->signInStore('disabled-method@example.test');
        StoreExtra::where('idTienda', $store->id)->update([
            'nameBanc1' => null, 'namePrope1' => null, 'transf1' => null,
        ]);
        $product = $this->createProduct($store->createdby, ['number' => '20'], 2);
        Cart::create([
            'product' => $product->id, 'price' => 20, 'dom' => $store->createdby,
            'user' => 'disabled-cart', 'variation' => $store->serial, 'cant' => 1, 'status' => 0,
        ]);

        $this->withHeaders(['X-Cart-Token' => 'disabled-cart', 'Idempotency-Key' => 'disabled-method'])
            ->postJson("/api/v1/stores/{$store->serial}/checkout", $this->checkoutPayload('bank_transfer'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');

        $this->assertDatabaseCount('ordencompra', 0);
        $this->assertDatabaseCount('order_payments', 0);
        $this->assertDatabaseCount('order_audit_events', 0);
        $this->assertDatabaseHas('stock', ['idProd' => $product->id, 'stock' => 2]);
        $this->assertDatabaseHas('cart', ['user' => 'disabled-cart', 'status' => 0]);
    }

    public function test_public_order_projection_is_owner_scoped_minimal_and_prioritizes_refund_pending(): void
    {
        [, $store] = $this->signInStore('projection@example.test');
        $order = $this->order($store, 'PROJECTION-1', 'projection-cart');
        $product = $this->createProduct($store->createdby, ['keyy' => 'Ticket product', 'number' => '25'], 1);
        $addon = ProductAddon::create(['idProd' => $product->id, 'nombre' => 'Ticket addon', 'precio' => 2, 'activo' => true]);
        $cart = Cart::create([
            'product' => $product->id, 'price' => 25, 'dom' => $store->createdby,
            'user' => 'projection-cart', 'variation' => $store->serial, 'cant' => 1,
            'status' => 2, 'orderC' => $order->order,
        ]);
        CartAddon::create(['noOrder' => $cart->id, 'idAditivo' => $addon->id, 'session' => 'projection-cart']);
        $foreign = Store::create(['serial' => 'PROJECTION-FOREIGN', 'createdby' => 'projection-foreign@example.test']);

        $this->withHeader('X-Cart-Token', 'wrong-cart')
            ->getJson("/api/v1/stores/{$store->serial}/orders/{$order->order}")->assertNotFound();
        $this->withHeader('X-Cart-Token', 'projection-cart')
            ->getJson("/api/v1/stores/{$foreign->serial}/orders/{$order->order}")->assertNotFound();

        $ticket = $this->withHeader('X-Cart-Token', 'projection-cart')
            ->getJson("/api/v1/stores/{$store->serial}/orders/{$order->order}")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.cliente', 'Private customer')
            ->assertJsonPath('data.items.0.name', 'Ticket product')
            ->assertJsonPath('data.items.0.addons.0.name', 'Ticket addon')
            ->assertJsonMissingPath('data.telefono')
            ->assertJsonMissingPath('data.provider')
            ->json('data');
        $this->assertSame([
            'order', 'status', 'payment_method', 'payment_status', 'order_state', 'delivery_type',
            'amount_due', 'currency', 'can_pay', 'can_cancel', 'can_submit_proof',
            'cliente', 'fecha', 'total', 'envio', 'items',
        ], array_keys($ticket));

        $projection = $this->withHeader('X-Cart-Token', 'projection-cart')
            ->getJson("/api/v1/stores/{$store->serial}/orders/{$order->order}/status")
            ->assertOk()->json('data');
        $this->assertSame([
            'order', 'status', 'payment_method', 'payment_status', 'order_state', 'delivery_type',
            'amount_due', 'currency', 'can_pay', 'can_cancel', 'can_submit_proof',
        ], array_keys($projection));

        $order->update(['order_state' => PurchaseOrder::STATE_CANCELLED, 'cancelled_at' => now()]);
        $order->payment()->update(['status' => 'refund_pending', 'amount_paid' => 25]);
        $this->withHeader('X-Cart-Token', 'projection-cart')
            ->getJson("/api/v1/stores/{$store->serial}/orders/{$order->order}/status")
            ->assertOk()
            ->assertJsonPath('data.status', 'refund_pending')
            ->assertJsonPath('data.can_cancel', false);
        $this->withHeader('X-Cart-Token', 'projection-cart')
            ->getJson("/api/v1/public/orders/{$order->id}")->assertNotFound();
    }

    public function test_order_notification_html_escapes_all_dynamic_html_fields(): void
    {
        $order = new PurchaseOrder([
            'order' => '<script>alert(1)</script>',
            'nombre' => '<img src=x onerror=alert(2)>',
            'tel' => '"/><svg onload=alert(3)>',
            'date' => '<b>tomorrow</b>',
            'total' => 10,
        ]);

        $html = app(OrderNotificationHtml::class)->body($order);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<svg', $html);
        $this->assertStringNotContainsString('<b>tomorrow</b>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&quot;/&gt;&lt;svg', $html);
    }

    public function test_store_payment_settings_migration_retries_and_refuses_destructive_down_with_rows(): void
    {
        Schema::drop('store_payment_settings');
        $migration = require database_path('migrations/2026_09_23_000001_create_store_payment_settings_table.php');
        $migration->up();
        $migration->up();
        $this->assertTrue(Schema::hasColumn('store_payment_settings', 'cash_on_delivery_enabled'));

        DB::table('store_payment_settings')->insert([
            'store_id' => 99, 'cash_on_delivery_enabled' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            $migration->down();
            $this->fail('Rollback with payment settings must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('contains rows', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasTable('store_payment_settings'));

        DB::table('store_payment_settings')->delete();
        $migration->down();
        $this->assertFalse(Schema::hasTable('store_payment_settings'));
    }

    private function protect(Store $store, string $password): StorePassword
    {
        return StorePassword::create(['idTienda' => $store->id, 'keyMenu' => Hash::make($password)]);
    }

    private function unlock(Store $store, string $password): string
    {
        return $this->postJson("/api/v1/public/stores/{$store->serial}/unlock", ['password' => $password])
            ->assertOk()
            ->assertJsonStructure(['data' => ['capability', 'expires_at']])
            ->json('data.capability');
    }

    private function checkoutPayload(string $method): array
    {
        return [
            'nombre' => 'Cliente', 'telefono' => '5551112222',
            'tipo_envio' => 'pickup', 'payment_method' => $method,
        ];
    }

    private function order(Store $store, string $reference, string $token): PurchaseOrder
    {
        $order = PurchaseOrder::create([
            'order' => $reference, 'serial' => $store->serial, 'session' => $token,
            'tel' => '555', 'nombre' => 'Private customer', 'lat' => '', 'long' => '',
            'date' => now(), 'total' => 25, 'totEnvio' => 0,
            'order_state' => PurchaseOrder::STATE_PENDING, 'delivery_type' => 'pickup',
        ]);
        OrderPayment::create([
            'order_id' => $order->id, 'store_id' => $store->id, 'method' => 'mercado_pago',
            'terms' => 'prepaid', 'status' => 'pending', 'currency' => 'MXN',
            'products_amount' => 25, 'discount_amount' => 0, 'shipping_amount' => 0,
            'extra_amount' => 0, 'amount_due' => 25, 'amount_paid' => 0, 'amount_refunded' => 0,
        ]);

        return $order;
    }
}
