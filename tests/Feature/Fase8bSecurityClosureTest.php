<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Cart;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Coupon;
use App\Models\MercadoPagoAccount;
use App\Models\OrderPayment;
use App\Models\ProductAddon;
use App\Models\PurchaseOrder;
use App\Models\Store;
use App\Models\StorePassword;
use App\Models\StorePaymentSetting;
use App\Models\User;
use App\Services\AppointmentNotificationHtml;
use App\Services\StorePaymentMethods;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\InteractsWithSalesSchema;
use Tests\TestCase;

class Fase8bSecurityClosureTest extends TestCase
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

    public function test_marketplace_hides_protected_known_ids_related_categories_profiles_and_cart(): void
    {
        [, $open] = $this->signInStore('market-open@example.test');
        $open->update(['category' => '1']);
        $openProduct = $this->createProduct($open->createdby, ['keyy' => 'Open', 'category' => 'Shared'], 5);
        $this->createProduct($open->createdby, ['keyy' => 'Open category', 'category' => 'OpenOnly'], 5);

        [, $protected] = $this->signInStore('market-protected@example.test');
        $protected->update(['category' => '1']);
        $secret = $this->createProduct($protected->createdby, ['keyy' => 'Secret', 'category' => 'Shared'], 5);
        $this->createProduct($protected->createdby, ['keyy' => 'Secret category', 'category' => 'SecretOnly'], 5);
        $this->protect($protected, 'market-secret');

        $this->getJson("/api/v1/marketplace/product/{$secret->id}")->assertNotFound();
        $show = $this->getJson("/api/v1/marketplace/product/{$openProduct->id}")
            ->assertOk()
            ->json('data.related');
        $this->assertNotContains($secret->id, collect($show)->pluck('id')->all());

        $categories = $this->getJson('/api/v1/marketplace/categories')->assertOk()->json('data');
        $this->assertContains('OpenOnly', $categories);
        $this->assertNotContains('SecretOnly', $categories);
        $this->getJson("/api/v1/marketplace/store/{$protected->id}")->assertNotFound();

        Cart::create([
            'product' => $openProduct->id, 'price' => 10, 'dom' => $open->createdby,
            'user' => 'market-cart', 'variation' => $open->serial, 'cant' => 1, 'status' => 0,
        ]);
        Cart::create([
            'product' => $secret->id, 'price' => 99, 'dom' => $protected->createdby,
            'user' => 'market-cart', 'variation' => $protected->serial, 'cant' => 1, 'status' => 0,
        ]);
        $this->withHeader('X-Cart-Token', 'market-cart')
            ->getJson('/api/v1/marketplace/cart')
            ->assertOk()
            ->assertJsonCount(1, 'data.stores')
            ->assertJsonPath('data.stores.0.store_serial', $open->serial)
            ->assertJsonPath('data.total_price', 10);
    }

    public function test_serial_product_routes_enforce_tenancy_and_legacy_routes_are_gone(): void
    {
        [, $first] = $this->signInStore('serial-first@example.test');
        $product = $this->createProduct($first->createdby);
        ProductAddon::create(['idProd' => $product->id, 'nombre' => 'Extra', 'precio' => 1, 'activo' => true]);
        [, $second] = $this->signInStore('serial-second@example.test');

        $this->getJson("/api/v1/public/stores/{$second->serial}/products/{$product->id}")->assertNotFound();
        $this->getJson("/api/v1/public/stores/{$second->serial}/products/{$product->id}/addons")->assertNotFound();
        $this->getJson("/api/v1/public/stores/{$first->serial}/products/not-a-number")->assertNotFound();
        $this->getJson("/api/v1/public/stores/{$first->serial}/products/not-a-number/addons")->assertNotFound();
        $this->getJson("/api/v1/public/products/{$product->id}")->assertNotFound();
        $this->getJson("/api/v1/public/products/{$product->id}/addons")->assertNotFound();
    }

    public function test_capability_gates_every_cart_coupon_and_clear_verb(): void
    {
        [, $store] = $this->signInStore('cart-verbs@example.test');
        $product = $this->createProduct($store->createdby, ['number' => '10'], 10);
        $this->protect($store, 'cart-pass');
        $cart = $this->cart($store, $product->id, 'cart-verbs');

        $this->withHeader('X-Cart-Token', 'cart-verbs')->getJson("/api/v1/stores/{$store->serial}/cart")->assertUnauthorized();
        $this->withHeader('X-Cart-Token', 'cart-verbs')->postJson("/api/v1/stores/{$store->serial}/cart", [
            'product_id' => $product->id, 'quantity' => 1,
        ])->assertUnauthorized();
        $this->withHeader('X-Cart-Token', 'cart-verbs')->putJson("/api/v1/stores/{$store->serial}/cart/{$cart->id}", [
            'quantity' => 2,
        ])->assertUnauthorized();
        $this->withHeader('X-Cart-Token', 'cart-verbs')->deleteJson("/api/v1/stores/{$store->serial}/cart/{$cart->id}")->assertUnauthorized();
        $this->withHeader('X-Cart-Token', 'cart-verbs')->deleteJson("/api/v1/stores/{$store->serial}/cart")->assertUnauthorized();
        $this->withHeader('X-Cart-Token', 'cart-verbs')->postJson("/api/v1/stores/{$store->serial}/cart/coupon", [
            'code' => 'VERBS',
        ])->assertUnauthorized();
        $this->assertDatabaseHas('cart', ['id' => $cart->id, 'cant' => 1]);

        Coupon::create([
            'idTienda' => $store->id, 'nombre' => 'Verbs', 'tipo' => '1', 'codeC' => 'VERBS',
            'uses' => 1, 'starts' => now()->subDay(), 'expired' => now()->addDay(),
            'cant' => 1, 'porcent' => 0, 'valorCompra' => 0,
        ]);
        $capability = $this->unlock($store, 'cart-pass');
        $headers = ['X-Cart-Token' => 'cart-verbs', 'X-Store-Capability' => $capability];
        $this->withHeaders($headers)->getJson("/api/v1/stores/{$store->serial}/cart")->assertOk();
        $this->withHeaders($headers)->putJson("/api/v1/stores/{$store->serial}/cart/{$cart->id}", ['quantity' => 2])->assertOk();
        $this->withHeaders($headers)->postJson("/api/v1/stores/{$store->serial}/cart/coupon", ['code' => 'VERBS'])->assertOk();
        $this->withHeaders($headers)->deleteJson("/api/v1/stores/{$store->serial}/cart/{$cart->id}")->assertOk();
        $created = $this->withHeaders($headers)->postJson("/api/v1/stores/{$store->serial}/cart", [
            'product_id' => $product->id, 'quantity' => 1,
        ])->assertCreated()->json('data.id');
        $this->assertNotNull($created);
        $this->withHeaders($headers)->deleteJson("/api/v1/stores/{$store->serial}/cart")->assertOk();
        $this->assertDatabaseMissing('cart', ['user' => 'cart-verbs', 'status' => 0]);
    }

    public function test_unlock_throttle_uses_case_insensitive_store_bucket_and_global_ip_limit(): void
    {
        [, $store] = $this->signInStore('unlock-throttle@example.test');
        $this->protect($store, 'throttle-pass');

        foreach (range(1, 5) as $attempt) {
            $serial = $attempt % 2 === 0 ? strtolower($store->serial) : strtoupper($store->serial);
            $this->postJson("/api/v1/public/stores/{$serial}/unlock", ['password' => 'throttle-pass'])
                ->assertOk();
        }
        $this->postJson('/api/v1/public/stores/'.strtolower($store->serial).'/unlock', ['password' => 'throttle-pass'])
            ->assertTooManyRequests();
    }

    public function test_public_chat_is_fail_closed_without_reads_or_writes_while_owner_chat_remains_available(): void
    {
        [, $store] = $this->signInStore('chat-owner@example.test');
        $conversation = ChatConversation::create([
            'store_id' => $store->id, 'customer_name' => 'Cliente', 'session_id' => 'private-chat',
        ]);
        ChatMessage::create(['conversation_id' => $conversation->id, 'sender_type' => 'customer', 'message' => 'Secret']);

        $this->postJson('/api/v1/chat/start', ['store_serial' => $store->serial])->assertStatus(503);
        $this->getJson("/api/v1/chat/{$conversation->id}/messages")->assertStatus(503);
        $this->postJson("/api/v1/chat/{$conversation->id}/send", ['message' => 'Mutation'])->assertStatus(503);
        $this->assertDatabaseCount('chat_conversations', 1);
        $this->assertDatabaseCount('chat_messages', 1);
        $this->assertNull(ChatMessage::firstOrFail()->read_at);

        $this->getJson('/api/v1/chat/conversations')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_booking_uses_route_tenant_capability_and_escaped_email_formatter(): void
    {
        [$owner, $store] = $this->signInStore('booking-owner@example.test');
        $this->protect($store, 'booking-pass');
        [, $other] = $this->signInStore('booking-other@example.test');
        $this->protect($other, 'other-pass');
        $otherCapability = $this->unlock($other, 'other-pass');
        $payload = [
            'nombre' => '<script>Client</script>', 'telefono' => '"><svg>',
            'fecha_apartada' => '2026-10-01 10:00:00', 'texto' => '<img src=x>',
        ];

        $this->postJson("/api/v1/public/stores/{$store->serial}/bookings", $payload)->assertUnauthorized();
        $this->withHeader('X-Store-Capability', $otherCapability)
            ->postJson("/api/v1/public/stores/{$store->serial}/bookings", $payload)->assertForbidden();
        $capability = $this->unlock($store, 'booking-pass');
        $this->withHeader('X-Store-Capability', $capability)
            ->postJson("/api/v1/public/stores/{$store->serial}/bookings", $payload + ['store_serial' => $other->serial])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('store_serial');
        $this->withHeader('X-Store-Capability', $capability)
            ->postJson("/api/v1/public/stores/{$store->serial}/bookings", $payload)
            ->assertCreated();
        $this->assertDatabaseHas('agenda', ['idLog' => $owner->id, 'nombre' => '<script>Client</script>']);
        $this->postJson('/api/v1/public/bookings', $payload + ['store_serial' => $store->serial])->assertNotFound();

        $html = app(AppointmentNotificationHtml::class)->body(Appointment::firstOrFail());
        $subject = app(AppointmentNotificationHtml::class)->subject(Appointment::firstOrFail());
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<svg>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $subject);
        $this->assertStringContainsString('&lt;script&gt;', $subject);
    }

    public function test_checkout_exact_replay_precedes_expired_or_revoked_capability_but_conflicts_still_win(): void
    {
        Carbon::setTestNow('2026-09-23 10:00:00');
        config(['store_catalog.capability_ttl_minutes' => 1]);
        [, $store] = $this->signInStore('checkout-replay-cap@example.test');
        $this->protect($store, 'first-pass');
        $product = $this->createProduct($store->createdby, ['number' => '10'], 3);
        $payload = $this->checkoutPayload('cash');

        $this->cart($store, $product->id, 'expired-replay');
        $expiredCapability = $this->unlock($store, 'first-pass');
        $headers = [
            'X-Cart-Token' => 'expired-replay', 'Idempotency-Key' => 'expired-replay-key',
            'X-Store-Capability' => $expiredCapability,
        ];
        $this->withHeaders($headers)
            ->postJson('/api/v1/stores/'.mb_strtolower($store->serial).'/checkout', $payload)
            ->assertCreated();
        $this->assertDatabaseHas('ordencompra', [
            'session' => 'expired-replay',
            'serial' => $store->serial,
        ]);
        Carbon::setTestNow('2026-09-23 10:01:00');
        $this->withHeaders($headers)->postJson("/api/v1/stores/{$store->serial}/checkout", $payload)
            ->assertOk()->assertJsonPath('data.idempotent', true);
        $this->withHeaders($headers)->postJson("/api/v1/stores/{$store->serial}/checkout", [...$payload, 'nombre' => 'Different'])
            ->assertConflict();

        Carbon::setTestNow('2026-09-23 11:00:00');
        $this->cart($store, $product->id, 'revoked-replay');
        $revokedCapability = $this->unlock($store, 'first-pass');
        $revokedHeaders = [
            'X-Cart-Token' => 'revoked-replay', 'Idempotency-Key' => 'revoked-replay-key',
            'X-Store-Capability' => $revokedCapability,
        ];
        $this->withHeaders($revokedHeaders)->postJson("/api/v1/stores/{$store->serial}/checkout", $payload)->assertCreated();
        $this->putJson('/api/v1/store/catalog-password', ['catalog_pass' => 'second-pass'])->assertOk();
        $this->withHeaders($revokedHeaders)->postJson("/api/v1/stores/{$store->serial}/checkout", $payload)
            ->assertOk()->assertJsonPath('data.idempotent', true);

        $this->cart($store, $product->id, 'new-protected');
        $this->withHeaders([
            'X-Cart-Token' => 'new-protected', 'Idempotency-Key' => 'new-protected',
            'X-Store-Capability' => '',
        ])
            ->postJson("/api/v1/stores/{$store->serial}/checkout", $payload)->assertUnauthorized();
        $this->withHeaders([
            'X-Cart-Token' => 'new-protected', 'Idempotency-Key' => 'new-protected',
            'X-Store-Capability' => 'invalid',
        ])->postJson("/api/v1/stores/{$store->serial}/checkout", $payload)->assertForbidden();
        $this->assertDatabaseCount('ordencompra', 2);
        $this->assertDatabaseHas('stock', ['idProd' => $product->id, 'stock' => 1]);
    }

    public function test_zero_amount_terminal_payment_is_paid_and_public_cancel_never_restocks(): void
    {
        [, $store] = $this->signInStore('zero-payment@example.test');
        $product = $this->createProduct($store->createdby, ['number' => '0'], 1);
        $order = $this->order($store, 'ZERO-ORDER', 'zero-cart', 'paid', 0);
        Cart::create([
            'product' => $product->id, 'price' => 0, 'dom' => $store->createdby,
            'user' => 'zero-cart', 'variation' => $store->serial, 'cant' => 1,
            'status' => 2, 'orderC' => $order->order,
        ]);

        $this->assertTrue($order->fresh()->isPaid());
        $this->withHeader('X-Cart-Token', 'zero-cart')
            ->getJson('/api/v1/stores/'.mb_strtolower($store->serial)."/orders/{$order->order}/status")
            ->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.can_cancel', false);
        $this->withHeaders(['X-Cart-Token' => 'zero-cart', 'Idempotency-Key' => 'zero-cancel'])
            ->postJson("/api/v1/stores/{$store->serial}/orders/{$order->order}/cancel")
            ->assertUnprocessable();
        $this->assertDatabaseHas('stock', ['idProd' => $product->id, 'stock' => 1]);

        $order->payment()->update(['status' => 'pending']);
        $order->update(['order_state' => PurchaseOrder::STATE_PAID]);
        $this->assertTrue($order->fresh()->isPaid());
    }

    public function test_payment_methods_expand_contract_without_settings_table(): void
    {
        [$owner, $store] = $this->signInStore('expand-contract@example.test');
        MercadoPagoAccount::create([
            'idLog' => $owner->id, 'merchantId' => 'expand-merchant', 'secretKey' => 'secret', 'publicKey' => 'public',
        ]);
        Schema::drop('store_payment_settings');

        $methods = app(StorePaymentMethods::class)->resolve($store);
        $this->assertTrue($methods['cash']);
        $this->assertTrue($methods['bank_transfer']);
        $this->assertTrue($methods['mercado_pago']);
        $this->assertFalse($methods['cash_on_delivery']);
        $this->getJson("/api/v1/public/stores/{$store->serial}/theme")
            ->assertOk()->assertJsonPath('data.payment_methods.cash_on_delivery', false);
    }

    public function test_paid_legacy_manual_conversion_persists_its_one_shot_reference(): void
    {
        [, $store] = $this->signInStore('legacy-terminal@example.test');
        $order = $this->order($store, 'LEGACY-TERMINAL', 'legacy-terminal-cart', 'paid', 0);
        $order->update(['order_state' => PurchaseOrder::STATE_PAID]);
        $order->payment()->update(['method' => 'legacy_unknown']);

        $this->withHeader('Idempotency-Key', 'legacy-terminal-cash')
            ->putJson("/api/v1/orders/{$order->id}/confirm-payment", [
                'method' => 'cash', 'reference' => 'LEGACY-ZERO-REF',
            ])->assertOk()->assertJsonPath('data.idempotent', true);
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'method' => 'cash',
            'status' => 'paid',
            'cash_reference' => 'LEGACY-ZERO-REF',
        ]);

        $this->withHeader('Idempotency-Key', 'legacy-terminal-change')
            ->putJson("/api/v1/orders/{$order->id}/confirm-payment", [
                'method' => 'bank_transfer', 'reference' => 'NOPE',
            ])->assertUnprocessable()
            ->assertJsonPath('message', 'El metodo de cobro no puede cambiarse.');
    }

    public function test_cod_owner_settings_are_tenant_scoped_permissioned_and_deny_superadmin_mutation(): void
    {
        [$owner, $store] = $this->signInStore('cod-settings@example.test');
        $foreign = Store::create(['serial' => 'COD-FOREIGN', 'createdby' => 'cod-foreign@example.test']);
        StorePaymentSetting::create(['store_id' => $foreign->id, 'cash_on_delivery_enabled' => false]);

        $this->getJson('/api/v1/store/payment-settings')
            ->assertOk()->assertJsonPath('data.cash_on_delivery_enabled', false);
        $this->putJson('/api/v1/store/payment-settings', [
            'cash_on_delivery_enabled' => true, 'store_id' => $foreign->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('store_id');
        $this->putJson('/api/v1/store/payment-settings', ['cash_on_delivery_enabled' => true])
            ->assertOk()->assertJsonPath('data.cash_on_delivery_enabled', true);
        $this->assertDatabaseHas('store_payment_settings', ['store_id' => $store->id, 'cash_on_delivery_enabled' => true]);
        $this->assertDatabaseHas('store_payment_settings', ['store_id' => $foreign->id, 'cash_on_delivery_enabled' => false]);

        $owner->revokePermissionTo('payments.mercado-pago.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->getJson('/api/v1/store/payment-settings')->assertForbidden();
        $owner->givePermissionTo(Permission::findOrCreate('payments.mercado-pago.manage', 'web'));
        $owner->assignRole(Role::findOrCreate('super-admin', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->putJson('/api/v1/store/payment-settings', ['cash_on_delivery_enabled' => false])->assertForbidden();

        $admin = User::create(['name' => 'cod-pure-admin@example.test', 'keyvalue' => 'x', 'type' => '0', 'active' => true]);
        $admin->givePermissionTo(Permission::findOrCreate('payments.mercado-pago.manage', 'web'));
        $admin->assignRole(Role::findOrCreate('super-admin', 'web'));
        Sanctum::actingAs($admin, ['*']);
        $this->putJson('/api/v1/store/payment-settings', ['cash_on_delivery_enabled' => false])->assertForbidden();
    }

    public function test_catalog_identity_migration_preflights_duplicates_then_adds_retryable_uniques(): void
    {
        Schema::drop('passcatalago');
        Schema::drop('liks');
        Schema::create('liks', function (Blueprint $table) {
            $table->id();
            $table->string('serial');
            $table->string('createdby');
        });
        Schema::create('passcatalago', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idTienda');
            $table->string('keyMenu');
        });
        DB::table('liks')->insert([
            ['id' => 1, 'serial' => 'DUP', 'createdby' => 'same@example.test'],
            ['id' => 2, 'serial' => 'DUP', 'createdby' => 'same@example.test'],
        ]);
        DB::table('passcatalago')->insert([
            ['idTienda' => 1, 'keyMenu' => 'a'], ['idTienda' => 1, 'keyMenu' => 'b'],
        ]);
        $migration = require database_path('migrations/2026_09_23_000002_harden_store_catalog_identity_uniques.php');

        $this->assertMigrationFails($migration, 'duplicate passcatalago.idTienda');
        DB::table('passcatalago')->where('keyMenu', 'b')->delete();
        $this->assertMigrationFails($migration, 'duplicate liks.serial');
        DB::table('liks')->where('id', 2)->update(['serial' => 'UNIQUE']);
        $this->assertMigrationFails($migration, 'duplicate liks.createdby');
        DB::table('liks')->where('id', 2)->update(['createdby' => 'unique@example.test']);

        $migration->up();
        $migration->up();
        $this->assertTrue(Schema::hasIndex('passcatalago', ['idTienda'], 'unique'));
        $this->assertTrue(Schema::hasIndex('liks', ['serial'], 'unique'));
        $this->assertTrue(Schema::hasIndex('liks', ['createdby'], 'unique'));
        $migration->down();
        $this->assertTrue(Schema::hasIndex('liks', ['serial'], 'unique'));
    }

    public function test_settings_migration_rejects_partial_duplicate_and_unsafe_default_schemas(): void
    {
        Schema::drop('store_payment_settings');
        Schema::create('store_payment_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->boolean('cash_on_delivery_enabled')->default(false);
        });
        $migration = require database_path('migrations/2026_09_23_000001_create_store_payment_settings_table.php');
        $this->assertMigrationFails($migration, 'missing created_at');

        Schema::drop('store_payment_settings');
        Schema::create('store_payment_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->boolean('cash_on_delivery_enabled')->default(false);
            $table->timestamps();
        });
        DB::table('store_payment_settings')->insert([
            ['store_id' => 1, 'cash_on_delivery_enabled' => false],
            ['store_id' => 1, 'cash_on_delivery_enabled' => false],
        ]);
        $this->assertMigrationFails($migration, 'duplicate store_id');

        Schema::drop('store_payment_settings');
        Schema::create('store_payment_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->unique();
            $table->boolean('cash_on_delivery_enabled')->default(true);
            $table->timestamps();
        });
        $this->assertMigrationFails($migration, 'expected false');
    }

    public function test_capability_is_not_reflected_by_protected_resources_or_application_logs(): void
    {
        [, $store] = $this->signInStore('capability-no-leak@example.test');
        $this->createProduct($store->createdby);
        $this->protect($store, 'no-leak-pass');
        $capability = $this->unlock($store, 'no-leak-pass');
        Log::spy();

        $response = $this->withHeader('X-Store-Capability', $capability)
            ->getJson("/api/v1/public/stores/{$store->serial}/products")
            ->assertOk();
        $this->assertStringNotContainsString($capability, $response->getContent());
        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
    }

    public function test_payment_exception_blocks_public_cancel_without_claiming_collected_funds(): void
    {
        [, $store] = $this->signInStore('exception-payment@example.test');
        $product = $this->createProduct($store->createdby, ['number' => '10'], 2);
        $charge = $this->order($store, 'EXC-ORDER', 'exc-cart', 'payment_exception', 5);
        $charge->payment()->update(['amount_paid' => 5, 'amount_due' => 0]);
        $charge->payment()->update(['method' => 'mercado_pago']);
        Cart::create([
            'product' => $product->id, 'price' => 5, 'dom' => $store->createdby,
            'user' => 'exc-cart', 'variation' => $store->serial, 'cant' => 1,
            'status' => 2, 'orderC' => $charge->order,
        ]);

        // D2: excepcion de cobro NO son fondos cobrados, pero bloquean
        // cancelacion publica y reapertura de pago.
        $this->assertFalse($charge->fresh()->isPaid());
        $this->assertTrue($charge->fresh()->blocksCustomerCancellation());

        $this->withHeader('X-Cart-Token', 'exc-cart')
            ->getJson("/api/v1/stores/{$store->serial}/orders/{$charge->order}/status")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.payment_status', 'payment_exception')
            ->assertJsonPath('data.can_cancel', false)
            ->assertJsonPath('data.can_pay', false)
            ->assertJsonPath('data.can_submit_proof', false);

        $this->withHeaders(['X-Cart-Token' => 'exc-cart', 'Idempotency-Key' => 'exc-pay-key'])
            ->postJson("/api/v1/stores/{$store->serial}/orders/{$charge->order}/pay")
            ->assertUnprocessable();

        $charge->payment()->update(['method' => 'cash']);
        $this->withHeaders(['X-Cart-Token' => 'exc-cart', 'Idempotency-Key' => 'exc-cancel-key'])
            ->postJson("/api/v1/stores/{$store->serial}/orders/{$charge->order}/cancel")
            ->assertUnprocessable();
        $this->assertDatabaseHas('stock', ['idProd' => $product->id, 'stock' => 2]);

        // El dueno cancela: monto cobrado parcial/registrado -> refund_pending.
        $ownerCancel = $this->withHeader('Idempotency-Key', 'owner-cancel-1')
            ->postJson("/api/v1/orders/{$charge->id}/cancel")
            ->assertOk()->json('data');
        $this->assertTrue($ownerCancel['refund_required']);
        $this->assertSame('refund_pending', $charge->payment()->value('status'));

        // Excepcion sin monto cobrado: se cancela sin marcar reembolso.
        $exceptionZero = $this->order($store, 'EXC-ZERO', 'exc-zero-cart', 'payment_exception', 5);
        $exceptionZero->update(['order_state' => PurchaseOrder::STATE_PAID]);
        Cart::create([
            'product' => $product->id, 'price' => 5, 'dom' => $store->createdby,
            'user' => 'exc-zero-cart', 'variation' => $store->serial, 'cant' => 1,
            'status' => 3, 'orderC' => $exceptionZero->order,
        ]);
        $this->assertFalse($exceptionZero->fresh()->isPaid());
        $this->assertTrue($exceptionZero->fresh()->blocksCustomerCancellation());
        $this->withHeader('Idempotency-Key', 'exception-confirm')
            ->putJson("/api/v1/orders/{$exceptionZero->id}/confirm-payment")
            ->assertUnprocessable();
        $this->assertSame('payment_exception', $exceptionZero->payment()->value('status'));
        $ownerResult = $this->withHeader('Idempotency-Key', 'owner-cancel-2')
            ->postJson("/api/v1/orders/{$exceptionZero->id}/cancel")
            ->assertOk()->json('data');
        $this->assertFalse($ownerResult['refund_required']);
        $this->assertSame('payment_exception', $exceptionZero->payment()->value('status'));
    }

    public function test_public_store_profile_exposes_minimal_commercial_fields_without_pii(): void
    {
        [, $store] = $this->signInStore('public-profile@example.test');
        $store->update([
            'phone' => '55-5555-5555', 'adress' => 'Calle secreta 1', 'lat' => '19.0', 'long' => '-99.1',
        ]);
        DB::table('masdatosdetienda')->where('idTienda', $store->id)
            ->update(['nombreTienda' => '<b>Tienda Publica</b>']);

        $data = $this->getJson("/api/v1/public/stores/{$store->serial}")
            ->assertOk()->json('data');
        $this->assertSame(['id', 'serial', 'category', 'logo', 'logojpg', 'name'], array_keys($data));
        $this->assertSame('Tienda Publica', $data['name']);
        $this->assertArrayNotHasKey('createdby', $data);
        $this->assertArrayNotHasKey('phone', $data);
        $this->assertArrayNotHasKey('adress', $data);
        $this->assertArrayNotHasKey('lat', $data);
        $this->assertArrayNotHasKey('long', $data);
        $this->assertArrayNotHasKey('expires_at', $data);

        // Tienda protegida: sin capability 401; con capability contrato minimo.
        $this->protect($store, 'profile-pass');
        $this->getJson("/api/v1/public/stores/{$store->serial}")->assertUnauthorized();
        $capability = $this->unlock($store, 'profile-pass');
        $protectedData = $this->withHeader('X-Store-Capability', $capability)
            ->getJson("/api/v1/public/stores/{$store->serial}")
            ->assertOk()->json('data');
        $this->assertSame(['id', 'serial', 'category', 'logo', 'logojpg', 'name'], array_keys($protectedData));
        $this->assertArrayNotHasKey('createdby', $protectedData);
        $this->withHeader('X-Store-Capability', $capability)
            ->getJson('/api/v1/public/stores/'.mb_strtolower($store->serial))
            ->assertOk()
            ->assertJsonPath('data.serial', $store->serial);
    }

    public function test_unlock_issues_from_the_row_locked_in_transaction_not_a_stale_read(): void
    {
        [, $store] = $this->signInStore('unlock-lock@example.test');
        $this->protect($store, 'old-pass');
        $product = $this->createProduct($store->createdby, ['number' => '10'], 5);

        $injected = false;
        $locked = false;
        $passwordReads = 0;
        $lockedReadLevel = null;
        DB::listen(function ($query) use (&$injected, &$locked, &$passwordReads, &$lockedReadLevel) {
            $sql = $query->sql;
            // SQLite no emite "for update": el lock de la tienda es la lectura
            // de liks que ocurre DENTRO de la transaccion (la consulta del
            // throttle corre a nivel 0, antes del controlador).
            if (str_contains($sql, 'liks') && DB::transactionLevel() >= 1) {
                $locked = true;
                if (! $injected) {
                    $injected = true;
                    DB::table('passcatalago')->update(['keyMenu' => Hash::make('new-pass')]);
                }
            }
            if (str_starts_with($sql, 'select') && str_contains($sql, 'passcatalago') && DB::transactionLevel() >= 1) {
                $passwordReads++;
                $lockedReadLevel = DB::transactionLevel();
            }
        });

        // Escenario A: la escritura concurrente "gano" antes de leer la fila
        // bajo lock. El cliente con la clave ANTIGUA debe fallar: la capability
        // nunca se emite desde una lectura previa al lock.
        $this->postJson('/api/v1/public/stores/'.$store->serial.'/unlock', ['password' => 'old-pass'])
            ->assertForbidden();
        $this->assertTrue($injected, 'La escritura concurrente no fue inyectada.');
        $this->assertTrue($locked, 'No se encontro el lock de la tienda en la transaccion de unlock.');
        $this->assertSame(1, $passwordReads, 'Se debe leer la contrasena UNA sola vez bajo lock.');
        $this->assertGreaterThanOrEqual(1, (int) $lockedReadLevel, 'La lectura de passcatalago debe ocurrir dentro de la transaccion.');

        // Escenario B: con la clave NUEVA la capability se emite y funciona,
        // porque corresponde a la fila actual (la leida bajo lock).
        $capability = $this->postJson('/api/v1/public/stores/'.$store->serial.'/unlock', ['password' => 'new-pass'])
            ->assertOk()->json('data.capability');
        $this->assertNotNull($capability);
        $this->withHeader('X-Store-Capability', $capability)
            ->getJson("/api/v1/public/stores/{$store->serial}/products")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_checkout_reads_payment_readiness_under_the_same_store_row_lock(): void
    {
        [, $store] = $this->signInStore('readiness-lock@example.test');
        $product = $this->createProduct($store->createdby, ['number' => '10'], 10);
        StorePaymentSetting::create(['store_id' => $store->id, 'cash_on_delivery_enabled' => true]);
        $this->cart($store, $product->id, 'readiness-lock');

        $events = [];
        DB::listen(function ($query) use (&$events) {
            $events[] = ['sql' => $query->sql, 'level' => DB::transactionLevel()];
        });

        $this->withHeaders([
            'X-Cart-Token' => 'readiness-lock',
            'Idempotency-Key' => 'readiness-lock-key',
        ])->postJson("/api/v1/stores/{$store->serial}/checkout", [
            'nombre' => 'Cliente', 'telefono' => '5551112222',
            'tipo_envio' => 'shipping', 'payment_method' => 'cash_on_delivery',
            'costo_envio' => 10, 'direccion' => 'Calle 1', 'ciudad' => 'CDMX',
            'codigo_postal' => '00000',
        ])->assertCreated();

        $lockIndex = null;
        foreach ($events as $index => $event) {
            if (str_contains($event['sql'], 'liks') && $event['level'] >= 1) {
                $lockIndex = $index;
                break;
            }
        }
        $this->assertNotNull($lockIndex, 'No hubo lock de tienda (liks dentro de la transaccion).');

        // La readiness de pago/entrega se lee DESPUES del lock de tienda y
        // DENTRO de la misma transaccion (cierra la ventana TOCTOU).
        foreach (['masdatosdetienda', 'mercadopagocuentas', 'store_payment_settings', 'caracteristicasadicionales'] as $table) {
            $read = null;
            foreach (array_slice($events, $lockIndex + 1) as $event) {
                if (str_contains($event['sql'], $table)) {
                    $read = $event;
                    break;
                }
            }
            $this->assertNotNull($read, "Readiness [$table] no leida tras el lock de tienda.");
            $this->assertGreaterThanOrEqual(1, (int) $read['level'], "Readiness [$table] leida fuera de la transaccion.");
        }
    }

    private function protect(Store $store, string $password): StorePassword
    {
        return StorePassword::create(['idTienda' => $store->id, 'keyMenu' => Hash::make($password)]);
    }

    private function unlock(Store $store, string $password): string
    {
        return $this->postJson("/api/v1/public/stores/{$store->serial}/unlock", ['password' => $password])
            ->assertOk()->json('data.capability');
    }

    private function cart(Store $store, int $productId, string $token): Cart
    {
        return Cart::create([
            'product' => $productId, 'price' => 10, 'dom' => $store->createdby,
            'user' => $token, 'variation' => $store->serial, 'cant' => 1, 'status' => 0,
        ]);
    }

    private function checkoutPayload(string $method): array
    {
        return [
            'nombre' => 'Cliente', 'telefono' => '5551112222',
            'tipo_envio' => 'pickup', 'payment_method' => $method,
        ];
    }

    private function order(Store $store, string $reference, string $token, string $paymentStatus, float $amount): PurchaseOrder
    {
        $order = PurchaseOrder::create([
            'order' => $reference, 'serial' => $store->serial, 'session' => $token,
            'tel' => 'private', 'nombre' => 'Cliente', 'lat' => '', 'long' => '',
            'date' => now(), 'total' => $amount, 'totEnvio' => 0,
            'order_state' => PurchaseOrder::STATE_PENDING, 'delivery_type' => 'pickup',
        ]);
        OrderPayment::create([
            'order_id' => $order->id, 'store_id' => $store->id, 'method' => 'cash',
            'terms' => 'prepaid', 'status' => $paymentStatus, 'currency' => 'MXN',
            'products_amount' => $amount, 'discount_amount' => 0, 'shipping_amount' => 0,
            'extra_amount' => 0, 'amount_due' => $amount, 'amount_paid' => 0, 'amount_refunded' => 0,
        ]);

        return $order;
    }

    private function assertMigrationFails(object $migration, string $message): void
    {
        try {
            $migration->up();
            $this->fail('Migration should have failed: '.$message);
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
