<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\MercadoPagoAccount;
use App\Models\MercadoPagoPayment;
use App\Models\OrderPayment;
use App\Models\OrderProviderTransaction;
use App\Models\PurchaseOrder;
use App\Models\ShippingOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\CanonicalOrderAmount;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\InteractsWithSalesSchema;
use Tests\TestCase;

class Fase8aCorrectiveTest extends TestCase
{
    use InteractsWithSalesSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesSchema();
    }

    public function test_webhook_second_charge_on_an_active_order_marks_payment_exception(): void
    {
        [$user, $store] = $this->signInStore('overpay@example.test');
        $order = $this->order($store, 'OVERPAY-ORDER', 80);
        $this->mpBaseline($order, 'overpay-cart');
        MercadoPagoAccount::create([
            'idLog' => $user->id, 'secretKey' => 'secret', 'publicKey' => 'public', 'merchantId' => '987',
        ]);
        config(['services.mercadopago.webhook_secret' => 'overpay-secret']);
        Http::fake([
            'api.mercadopago.com/v1/payments/3001' => Http::response([
                'external_reference' => $order->order, 'status' => 'approved',
                'transaction_amount' => 50, 'collector_id' => 987, 'currency_id' => 'MXN',
            ]),
            'api.mercadopago.com/v1/payments/3002' => Http::response([
                'external_reference' => $order->order, 'status' => 'approved',
                'transaction_amount' => 50, 'collector_id' => 987, 'currency_id' => 'MXN',
            ]),
        ]);

        foreach (['3001' => 'rq-over-1', '3002' => 'rq-over-2'] as $paymentId => $requestId) {
            $this->postWebhook($paymentId, $requestId, 'overpay-secret');
        }

        $this->assertDatabaseHas('order_provider_transactions', [
            'order_id' => $order->id, 'provider_payment_id' => '3002', 'amount' => 50,
        ]);
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id, 'status' => 'payment_exception', 'amount_paid' => '100.00',
        ]);
        $this->assertDatabaseHas('order_audit_events', [
            'order_id' => $order->id, 'event_type' => 'payment_exception',
        ]);
        // La orden sigue activa: el exceso requiere revision manual, no reembolso.
        $this->assertDatabaseHas('ordencompra', [
            'order' => $order->order, 'order_state' => PurchaseOrder::STATE_PAID,
        ]);
    }

    public function test_webhook_counts_a_previous_manual_collection_toward_overpayment(): void
    {
        [$user, $store] = $this->signInStore('manual-then-mp@example.test');
        $order = $this->order($store, 'MANUAL-MP-ORDER', 80);
        $this->mpBaseline($order, 'manual-mp-cart');
        OrderPayment::where('order_id', $order->id)->update([
            'status' => 'paid', 'amount_paid' => '50.00', 'amount_due' => '80.00',
        ]);
        MercadoPagoAccount::create([
            'idLog' => $user->id, 'secretKey' => 'secret', 'publicKey' => 'public', 'merchantId' => '987',
        ]);
        config(['services.mercadopago.webhook_secret' => 'manual-mp-secret']);
        Http::fake([
            'api.mercadopago.com/v1/payments/4001' => Http::response([
                'external_reference' => $order->order, 'status' => 'approved',
                'transaction_amount' => 50, 'collector_id' => 987, 'currency_id' => 'MXN',
            ]),
        ]);

        $this->postWebhook('4001', 'rq-manual-mp', 'manual-mp-secret');

        // 50 manuales + 50 MP = 100 > 80 de importe congelado.
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id, 'status' => 'payment_exception', 'amount_paid' => '100.00',
        ]);
        // El cobro manual previo se materializo como transaccion 'manual'
        // (append-only) con su referencia en lugar de una suma fantasma.
        $paymentId = OrderPayment::where('order_id', $order->id)->value('id');
        $this->assertDatabaseHas('order_provider_transactions', [
            'order_id' => $order->id, 'provider' => 'manual', 'provider_payment_id' => 'carryforward:'.$paymentId,
        ]);
    }

    public function test_cancelling_an_order_with_a_pending_proof_is_conflict_and_never_restocks(): void
    {
        [, $store] = $this->signInStore('proof-cancel@example.test');
        $order = $this->checkout($store, 'proof-cancel-cart', 'bank_transfer', 'pickup', 10, 1, 2);
        $productId = (int) Cart::where('user', 'proof-cancel-cart')->value('product');
        OrderPayment::where('order_id', $order->id)->update([
            'status' => 'proof_submitted', 'frozen_at' => now(), 'bank_reference' => 'REF-PROOF',
        ]);
        $this->assertDatabaseHas('stock', ['idProd' => $productId, 'stock' => 1]);

        $this->withHeader('Idempotency-Key', 'cancel-proof')
            ->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertConflict()
            ->assertJsonPath('message', 'El pago tiene un comprobante en revision.');
        $this->assertDatabaseHas('ordencompra', ['id' => $order->id, 'order_state' => PurchaseOrder::STATE_PENDING]);
        $this->assertDatabaseHas('stock', ['idProd' => $productId, 'stock' => 1]);
    }

    public function test_cancelling_an_order_with_unknown_departure_is_fail_closed_without_restock(): void
    {
        [, $store] = $this->signInStore('unknown-departure@example.test');
        $order = $this->checkout($store, 'unknown-departure-cart', 'bank_transfer', 'pickup', 10, 1, 2);
        $productId = (int) Cart::where('user', 'unknown-departure-cart')->value('product');
        ShippingOrder::create([
            'tienda' => $store->id,
            'ordenCompra' => $order->id,
            'fechaIn' => now(),
            'status' => '1',
            'assignment_mode' => 'direct',
            'departure_state' => 'unknown',
            'departed_at' => null,
        ]);

        $this->withHeader('Idempotency-Key', 'cancel-unknown')
            ->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.restocked', false)
            ->assertJsonPath('data.return_pending', true);
        $this->assertDatabaseHas('stock', ['idProd' => $productId, 'stock' => 1]);
        $this->assertDatabaseHas('order_returns', ['order_id' => $order->id, 'status' => 'pending']);
    }

    public function test_dispatch_records_an_audit_event_and_a_dual_role_superadmin_never_mutates_foreign_orders(): void
    {
        [$owner, $store] = $this->signInStore('dispatch-audit@example.test', ['orders.dispatch']);
        $order = $this->order($store, 'DISPATCH-AUDIT', 10);
        $shipping = ShippingOrder::create([
            'tienda' => $store->id,
            'delivery' => 42,
            'ordenCompra' => $order->id,
            'fechaIn' => now(),
            'status' => '1',
            'assignment_mode' => 'direct',
            'departure_state' => 'not_departed',
            'departed_at' => null,
        ]);

        $this->postJson("/api/v1/delivery/orders/{$shipping->id}/dispatch")
            ->assertOk()
            ->assertJsonPath('data.departure_state', 'departed');
        $this->assertDatabaseHas('order_audit_events', [
            'order_id' => $order->id, 'event_type' => 'order_dispatched', 'actor_type' => 'store_owner',
        ]);

        // Dual-role: el middleware 'permission:orders.dispatch' pasa (tiene el
        // permiso directo), el guard del controlador aborta 403 antes de mutar.
        $owner->assignRole(Role::findOrCreate('super-admin', 'web'));
        Sanctum::actingAs($owner, ['*']);

        // La orden objetivo es REAL y pertenece a OTRA tienda: el intento debe
        // ser 403 sin mutarla, sin renglones marcados salidos (status 5) y sin
        // ningun evento de auditoria adicional.
        $foreign = Store::create(['serial' => 'DISPATCH-FOREIGN', 'createdby' => 'dispatch-foreign@example.test']);
        $foreignOrder = PurchaseOrder::create([
            'order' => 'DISPATCH-FOREIGN-ORDER', 'serial' => $foreign->serial, 'session' => 'dispatch-foreign-cart',
            'tel' => '555', 'nombre' => 'Otro', 'lat' => '', 'long' => '', 'date' => now(),
            'total' => 50, 'totEnvio' => 0, 'order_state' => PurchaseOrder::STATE_PENDING,
        ]);
        $foreignShipping = ShippingOrder::create([
            'tienda' => $foreign->id,
            'delivery' => 43,
            'ordenCompra' => $foreignOrder->id,
            'fechaIn' => now(),
            'status' => '1',
            'assignment_mode' => 'direct',
            'departure_state' => 'not_departed',
            'departed_at' => null,
        ]);
        $this->postJson("/api/v1/delivery/orders/{$foreignShipping->id}/dispatch")->assertForbidden();
        $this->assertDatabaseHas('ordenenvio', ['id' => $foreignShipping->id, 'departure_state' => 'not_departed']);
        $this->assertDatabaseHas('ordencompra', ['id' => $foreignOrder->id, 'order_state' => PurchaseOrder::STATE_PENDING]);
        $this->assertDatabaseCount('order_audit_events', 1);
    }

    public function test_public_pay_with_two_http_keys_still_creates_a_single_preference(): void
    {
        [$user, $store] = $this->signInStore('online-once@example.test');
        $product = $this->createProduct($store->createdby, ['number' => '50'], 2);
        $order = $this->mpOrderWithItem($store, $product->id, 'MP-ONCE', 'online-once-cart', 50);
        MercadoPagoAccount::create([
            'idLog' => $user->id, 'secretKey' => 'secret', 'publicKey' => 'public', 'merchantId' => '456',
        ]);
        Http::fake([
            'api.mercadopago.com/checkout/preferences' => Http::response([
                'id' => 'PREF-ONCE', 'init_point' => 'https://sandbox.mercadopago.com/init', 'sandbox_init_point' => '',
            ]),
        ]);

        $this->withHeaders(['X-Cart-Token' => 'online-once-cart', 'Idempotency-Key' => 'http-key-a'])
            ->postJson("/api/v1/stores/{$store->serial}/orders/{$order->order}/pay")->assertOk();
        $this->withHeaders(['X-Cart-Token' => 'online-once-cart', 'Idempotency-Key' => 'http-key-b'])
            ->postJson("/api/v1/stores/{$store->serial}/orders/{$order->order}/pay")->assertOk();

        Http::assertSentCount(1);
        $this->assertDatabaseCount('mercadopago', 1);
        $this->assertDatabaseHas('order_audit_events', [
            'order_id' => $order->id, 'event_type' => 'mercado_pago_preference_created',
        ]);
    }

    public function test_print_friendly_money_rejects_more_than_two_decimals(): void
    {
        $amounts = app(CanonicalOrderAmount::class);
        try {
            $amounts->cents('0.005');
            $this->fail('Three decimals must be rejected.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }
        $this->assertSame(7, $amounts->cents('0.07'));
        $this->assertSame('0.01', $amounts->money(1));
        $this->assertSame('999999999999.99', $amounts->money(99999999999999));
        try {
            $amounts->money(100000000000000);
            $this->fail('Overflow must be rejected.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }
    }

    public function test_proof_uploads_are_rate_limited_after_five_attempts(): void
    {
        [, $store] = $this->signInStore('proof-throttle@example.test');
        Storage::fake('payment-proofs');
        $order = $this->checkout($store, 'throttle-cart', 'bank_transfer', 'pickup', 12);

        foreach (range(1, 5) as $attempt) {
            $this->withHeaders([
                'X-Cart-Token' => 'throttle-cart',
                'Idempotency-Key' => "throttle-proof-{$attempt}",
            ])->post("/api/v1/stores/{$store->serial}/orders/{$order->order}/transfer-proof", [
                'reference' => "THROTTLE-{$attempt}",
                'proof' => $this->png("throttle-{$attempt}.png"),
            ])->assertStatus(in_array($attempt, [4, 5], true) ? 422 : 201);
        }

        $this->withHeaders([
            'X-Cart-Token' => 'throttle-cart',
            'Idempotency-Key' => 'throttle-proof-6',
        ])->post("/api/v1/stores/{$store->serial}/orders/{$order->order}/transfer-proof", [
            'reference' => 'THROTTLE-6',
            'proof' => $this->png('throttle-6.png'),
        ])->assertStatus(429);
        $this->assertDatabaseCount('order_payment_proofs', 3);
    }

    public function test_extra_charge_is_rejected_after_a_transfer_proof_froze_the_amount(): void
    {
        [, $store] = $this->signInStore('extra-post-proof@example.test');
        Storage::fake('payment-proofs');
        $order = $this->checkout($store, 'post-proof-cart', 'bank_transfer', 'shipping', 30, 2, 3);

        $this->withHeaders([
            'X-Cart-Token' => 'post-proof-cart',
            'Idempotency-Key' => 'proof-freeze',
        ])->post("/api/v1/stores/{$store->serial}/orders/{$order->order}/transfer-proof", [
            'reference' => 'FREEZE-REF',
            'proof' => $this->png('freeze.png'),
        ])->assertCreated();

        $payment = OrderPayment::where('order_id', $order->id)->firstOrFail();
        $this->assertNotNull($payment->frozen_at);
        $this->assertSame('70.00', (string) $payment->amount_due);

        $this->withHeader('Idempotency-Key', 'extra-post-proof')
            ->postJson("/api/v1/orders/{$order->id}/extra-charge", ['precio' => 1, 'tipo' => 'late'])
            ->assertConflict();
        $this->assertDatabaseCount('gastosextras', 0);
    }

    public function test_public_order_detail_is_scoped_to_the_cart_token(): void
    {
        [, $store] = $this->signInStore('public-detail@example.test');
        $foreign = Store::create(['serial' => 'DETAIL-FOREIGN', 'createdby' => 'detail-foreign@example.test']);
        $own = $this->order($store, 'DETAIL-OWN', 10);
        $foreignOrder = PurchaseOrder::create([
            'order' => 'DETAIL-FOREIGN-ORDER', 'serial' => $foreign->serial, 'session' => 'public-detail-cart',
            'tel' => '555', 'nombre' => 'Otro', 'lat' => '', 'long' => '', 'date' => now(),
            'total' => 99, 'totEnvio' => 0, 'order_state' => PurchaseOrder::STATE_PENDING,
        ]);

        $this->withHeader('X-Cart-Token', 'public-detail-cart')
            ->getJson("/api/v1/public/orders/{$foreignOrder->id}")
            ->assertOk()
            ->assertJsonPath('data.order', 'DETAIL-FOREIGN-ORDER');
        $this->getJson("/api/v1/public/orders/{$own->id}")->assertNotFound();
    }

    public function test_canonical_migration_down_is_noop_on_an_empty_schema(): void
    {
        $this->resetToPre8aBaseline();
        $migration = $this->migration();
        $migration->up();
        $migration->down();

        foreach (['order_provider_transactions', 'order_payment_proofs', 'order_returns', 'order_audit_events', 'order_payments'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->assertFalse(Schema::hasColumn('ordencompra', 'delivery_type'));
        $this->assertFalse(Schema::hasColumn('ordenenvio', 'departure_state'));
        $this->assertFalse(Schema::hasColumn('ordenenvio', 'departed_at'));
    }

    public function test_clean_data_is_conflict_for_a_pure_superadmin_with_finance_rows(): void
    {
        [, $store] = $this->signInStore('clean-data@example.test');
        $this->order($store, 'CLEAN-DATA', 10);
        $admin = User::create(['name' => 'clean-admin@example.test', 'keyvalue' => 'test', 'type' => '0', 'active' => true]);
        Role::findOrCreate('super-admin', 'web');
        $admin->assignRole(Role::findOrCreate('super-admin', 'web'));
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/v1/admin/clean-data')->assertConflict();
        $this->assertDatabaseCount('order_payments', 1);
    }

    public function test_public_pay_without_idempotency_key_is_422_not_502(): void
    {
        [$user, $store] = $this->signInStore('pay-no-key@example.test');
        $product = $this->createProduct($store->createdby, ['number' => '50'], 2);
        $this->mpOrderWithItem($store, $product->id, 'MP-NO-KEY', 'pay-no-key-cart', 50);
        MercadoPagoAccount::create([
            'idLog' => $user->id, 'secretKey' => 'secret', 'publicKey' => 'public', 'merchantId' => '456',
        ]);
        Http::fake();

        $this->withHeader('X-Cart-Token', 'pay-no-key-cart')
            ->postJson("/api/v1/stores/{$store->serial}/orders/MP-NO-KEY/pay")
            ->assertStatus(422)
            ->assertJsonPath('message', 'El encabezado Idempotency-Key es obligatorio y debe tener maximo 100 caracteres.');
        Http::assertNothingSent();
    }

    public function test_public_pay_rejects_a_non_mp_order_without_calling_mercado_pago(): void
    {
        [, $store] = $this->signInStore('pay-cash-only@example.test');
        $order = $this->checkout($store, 'pay-cash-only-cart', 'cash', 'pickup', 12, 1, 2);
        Http::fake();

        $this->withHeaders([
            'X-Cart-Token' => 'pay-cash-only-cart',
            'Idempotency-Key' => 'pay-cash-only-1',
        ])->postJson("/api/v1/stores/{$store->serial}/orders/{$order->order}/pay")
            ->assertStatus(422)
            ->assertJsonPath('message', 'La orden no fue creada para MercadoPago.');
        Http::assertNothingSent();
    }

    public function test_public_pay_hides_transport_runtime_exception_and_rolls_back_snapshot(): void
    {
        [$user, $store] = $this->signInStore('pay-transport@example.test');
        $product = $this->createProduct($store->createdby, ['number' => '45'], 2);
        $order = $this->mpOrderWithItem($store, $product->id, 'MP-TRANSPORT', 'pay-transport-cart', 45);
        MercadoPagoAccount::create([
            'idLog' => $user->id, 'secretKey' => 'secret', 'publicKey' => 'public', 'merchantId' => '456',
        ]);
        Http::fake(function () {
            throw new RuntimeException('Can not initialize cURL handle: C:\\secret');
        });

        $response = $this->withHeaders([
            'X-Cart-Token' => 'pay-transport-cart',
            'Idempotency-Key' => 'pay-transport-1',
        ])->postJson("/api/v1/stores/{$store->serial}/orders/{$order->order}/pay")
            ->assertStatus(502)
            ->assertJsonPath('message', 'No fue posible crear la preferencia de pago.');

        $this->assertStringNotContainsString('Can not initialize cURL handle', $response->getContent());
        $this->assertStringNotContainsString('C:\\secret', $response->getContent());
        $this->assertDatabaseCount('mercadopago', 0);
        $this->assertDatabaseCount('order_audit_events', 0);
        $this->assertNull(OrderPayment::where('order_id', $order->id)->value('frozen_at'));
    }

    public function test_public_pay_maps_provider_500_and_malformed_response_to_generic_502(): void
    {
        [$user, $store] = $this->signInStore('pay-invalid-response@example.test');
        $product = $this->createProduct($store->createdby, ['number' => '35'], 3);
        $serverError = $this->mpOrderWithItem($store, $product->id, 'MP-500', 'pay-500-cart', 35);
        $malformed = $this->mpOrderWithItem($store, $product->id, 'MP-MALFORMED', 'pay-malformed-cart', 35);
        MercadoPagoAccount::create([
            'idLog' => $user->id, 'secretKey' => 'secret', 'publicKey' => 'public', 'merchantId' => '456',
        ]);
        Http::fake(function ($request) {
            if ($request['external_reference'] === 'MP-500') {
                return Http::response(['message' => 'provider internals'], 500);
            }

            return Http::response(['init_point' => 'https://invalid.test/without-id'], 200);
        });

        foreach ([
            [$serverError, 'pay-500-cart', 'pay-500-1'],
            [$malformed, 'pay-malformed-cart', 'pay-malformed-1'],
        ] as [$order, $cartToken, $idempotencyKey]) {
            $this->withHeaders([
                'X-Cart-Token' => $cartToken,
                'Idempotency-Key' => $idempotencyKey,
            ])->postJson("/api/v1/stores/{$store->serial}/orders/{$order->order}/pay")
                ->assertStatus(502)
                ->assertJsonPath('message', 'No fue posible crear la preferencia de pago.');

            $this->assertNull(OrderPayment::where('order_id', $order->id)->value('frozen_at'));
        }

        $this->assertDatabaseCount('mercadopago', 0);
        $this->assertDatabaseCount('order_audit_events', 0);
    }

    public function test_public_pay_maps_locked_order_state_rule_to_422(): void
    {
        [$user, $store] = $this->signInStore('pay-state-race@example.test');
        $product = $this->createProduct($store->createdby, ['number' => '55'], 2);
        $order = $this->mpOrderWithItem($store, $product->id, 'MP-STATE-RACE', 'pay-state-race-cart', 55);
        MercadoPagoAccount::create([
            'idLog' => $user->id, 'secretKey' => 'secret', 'publicKey' => 'public', 'merchantId' => '456',
        ]);
        Http::fake();

        $transitioned = false;
        DB::listen(function ($query) use (&$transitioned, $order) {
            if ($transitioned || ! str_contains($query->sql, 'mercadopagocuentas')) {
                return;
            }

            $transitioned = true;
            DB::table('ordencompra')->where('id', $order->id)->update([
                'order_state' => PurchaseOrder::STATE_CANCELLED,
                'cancelled_at' => now(),
            ]);
        });

        $this->withHeaders([
            'X-Cart-Token' => 'pay-state-race-cart',
            'Idempotency-Key' => 'pay-state-race-1',
        ])->postJson("/api/v1/stores/{$store->serial}/orders/{$order->order}/pay")
            ->assertStatus(422)
            ->assertJsonPath('message', 'La orden no admite una preferencia de pago.');

        $this->assertTrue($transitioned);
        Http::assertNothingSent();
        $this->assertDatabaseCount('mercadopago', 0);
        $this->assertDatabaseCount('order_audit_events', 0);
    }

    public function test_store_preference_without_idempotency_key_is_422_not_502(): void
    {
        [$user, $store] = $this->signInStore('pref-no-key@example.test');
        $product = $this->createProduct($store->createdby, ['number' => '40'], 2);
        $order = $this->mpOrderWithItem($store, $product->id, 'PREF-NO-KEY', 'pref-no-key-cart', 40);
        MercadoPagoAccount::create([
            'idLog' => $user->id, 'secretKey' => 'secret', 'publicKey' => 'public', 'merchantId' => '456',
        ]);
        Http::fake();

        $this->postJson("/api/v1/payments/orders/{$order->id}/preference")
            ->assertStatus(422)
            ->assertJsonPath('message', 'El encabezado Idempotency-Key es obligatorio y debe tener maximo 100 caracteres.');
        Http::assertNothingSent();
    }

    public function test_public_cancel_without_idempotency_key_is_422(): void
    {
        [, $store] = $this->signInStore('cancel-no-key@example.test');
        $order = $this->checkout($store, 'cancel-no-key-cart', 'cash', 'pickup', 10);

        // Ojo: withHeaders persiste en defaultHeaders; sobrescribir la clave
        // con un valor vacio equivale a ausente para OrderIdempotency::key().
        $this->withHeaders([
            'X-Cart-Token' => 'cancel-no-key-cart',
            'Idempotency-Key' => '',
        ])->postJson("/api/v1/stores/{$store->serial}/orders/{$order->order}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('message', 'El encabezado Idempotency-Key es obligatorio y debe tener maximo 100 caracteres.');
    }

    public function test_public_cancel_reports_the_restock_in_the_message(): void
    {
        [, $store] = $this->signInStore('cancel-restock-msg@example.test');
        $order = $this->checkout($store, 'cancel-restock-msg-cart', 'cash', 'pickup', 14, 1, 2);
        $productId = (int) Cart::where('user', 'cancel-restock-msg-cart')->value('product');

        $this->withHeaders([
            'X-Cart-Token' => 'cancel-restock-msg-cart',
            'Idempotency-Key' => 'cancel-restock-msg',
        ])->postJson("/api/v1/stores/{$store->serial}/orders/{$order->order}/cancel")
            ->assertOk()
            ->assertJsonPath('data.restocked', true)
            ->assertJsonPath('message', 'Orden cancelada. Se repuso el inventario.');
        $this->assertDatabaseHas('stock', ['idProd' => $productId, 'stock' => 2]);
    }

    public function test_public_cancel_reports_return_pending_message_when_already_departed(): void
    {
        [, $store] = $this->signInStore('public-cancel-departed@example.test');
        $order = $this->checkout($store, 'public-cancel-departed-cart', 'bank_transfer', 'pickup', 16, 1, 2);
        ShippingOrder::create([
            'tienda' => $store->id,
            'ordenCompra' => $order->id,
            'fechaIn' => now(),
            'status' => '1',
            'assignment_mode' => 'direct',
            'departure_state' => 'unknown',
            'departed_at' => null,
        ]);

        $this->withHeaders([
            'X-Cart-Token' => 'public-cancel-departed-cart',
            'Idempotency-Key' => 'public-cancel-departed',
        ])->postJson("/api/v1/stores/{$store->serial}/orders/{$order->order}/cancel")
            ->assertOk()
            ->assertJsonPath('data.restocked', false)
            ->assertJsonPath('data.return_pending', true)
            ->assertJsonPath('message', 'Orden cancelada. La entrega ya habia salido; se requiere retorno fisico.');
    }

    public function test_emit_shipping_is_denied_for_a_pure_superadmin(): void
    {
        [, $store] = $this->signInStore('emit-purist@example.test', ['delivery.manage']);
        $order = $this->order($store, 'EMIT-PURIST', 10);
        $admin = User::create(['name' => 'emit-purist-admin@example.test', 'keyvalue' => 'test', 'type' => '0', 'active' => true]);
        $admin->assignRole(Role::findOrCreate('super-admin', 'web'));
        Sanctum::actingAs($admin, ['*']);

        $this->postJson("/api/v1/orders/{$order->id}/emit-shipping", ['assignment_mode' => 'pool'])
            ->assertForbidden();
        $this->assertDatabaseCount('ordenenvio', 0);
        $this->assertDatabaseCount('order_audit_events', 0);
    }

    public function test_emit_shipping_denies_a_dual_role_superadmin_before_mutating(): void
    {
        [$owner, $store] = $this->signInStore('emit-superadmin@example.test', ['delivery.manage']);
        $order = $this->order($store, 'EMIT-SUPERADMIN', 10);

        // Dual-role: el middleware 'role_or_permission:super-admin|delivery.manage'
        // deja pasar (tiene el rol), el guard del controlador aborta 403 antes
        // de tocar cualquier fila.
        $owner->assignRole(Role::findOrCreate('super-admin', 'web'));
        Sanctum::actingAs($owner, ['*']);

        $this->postJson("/api/v1/orders/{$order->id}/emit-shipping", ['assignment_mode' => 'pool'])
            ->assertForbidden();
        $this->assertDatabaseCount('ordenenvio', 0);
        $this->assertDatabaseCount('order_audit_events', 0);
    }

    public function test_webhook_accepts_a_legacy_unknown_order_with_mp_evidence_and_skips_returned_items(): void
    {
        [$user, $store] = $this->signInStore('legacy-webhook@example.test');
        $order = PurchaseOrder::create([
            'order' => 'LEGACY-MP-ORDER', 'serial' => $store->serial, 'session' => 'legacy-webhook-cart',
            'tel' => '5551112222', 'nombre' => 'Cliente', 'lat' => '', 'long' => '', 'date' => now(),
            'total' => 80, 'totEnvio' => 0, 'order_state' => PurchaseOrder::STATE_PENDING,
        ]);
        Cart::create(['product' => 1, 'price' => 75, 'dom' => $store->createdby, 'user' => 'legacy-webhook-cart',
            'variation' => $store->serial, 'cant' => 1, 'status' => 5, 'orderC' => $order->order]);
        Cart::create(['product' => 2, 'price' => 5, 'dom' => $store->createdby, 'user' => 'legacy-webhook-cart',
            'variation' => $store->serial, 'cant' => 1, 'status' => 2, 'orderC' => $order->order]);
        OrderPayment::create([
            'order_id' => $order->id, 'store_id' => $store->id, 'method' => 'legacy_unknown',
            'terms' => 'prepaid', 'status' => 'pending', 'currency' => 'MXN',
            'products_amount' => 80, 'discount_amount' => 0, 'shipping_amount' => 0,
            'extra_amount' => 0, 'amount_due' => 80, 'amount_paid' => 0, 'amount_refunded' => 0,
        ]);
        MercadoPagoPayment::create(['orderP' => $order->order, 'status' => 0, 'preference' => '', 'fecha' => null]);
        MercadoPagoAccount::create([
            'idLog' => $user->id, 'secretKey' => 'secret', 'publicKey' => 'public', 'merchantId' => '987',
        ]);
        config(['services.mercadopago.webhook_secret' => 'legacy-secret']);
        Http::fake([
            'api.mercadopago.com/v1/payments/5001' => Http::response([
                'external_reference' => $order->order, 'status' => 'approved',
                'transaction_amount' => 80, 'collector_id' => 987, 'currency_id' => 'MXN',
            ]),
        ]);

        $this->postWebhook('5001', 'rq-legacy-mp', 'legacy-secret');

        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id, 'method' => 'mercado_pago', 'status' => 'paid',
            'provider' => 'mercado_pago', 'provider_payment_id' => '5001',
        ]);
        $this->assertDatabaseHas('order_provider_transactions', [
            'order_id' => $order->id, 'provider_payment_id' => '5001', 'amount' => 80,
        ]);
        $this->assertDatabaseHas('mercadopago', ['orderP' => $order->order, 'status' => 1]);
        $this->assertDatabaseHas('ordencompra', ['id' => $order->id, 'order_state' => PurchaseOrder::STATE_PAID]);
        // El renglon devuelto (status 5) no se toca; el pendiente pasa a pagado (3).
        $this->assertDatabaseHas('cart', ['orderC' => $order->order, 'product' => 1, 'status' => 5]);
        $this->assertDatabaseHas('cart', ['orderC' => $order->order, 'product' => 2, 'status' => 3]);
    }

    public function test_webhook_re_notification_of_a_backfilled_payment_id_canonicalizes_and_does_not_duplicate(): void
    {
        [$user, $store] = $this->signInStore('backfill-renotify@example.test');
        $order = PurchaseOrder::create([
            'order' => 'BACKFILL-RENOTIFY', 'serial' => $store->serial, 'session' => 'backfill-renotify-cart',
            'tel' => '5551112222', 'nombre' => 'Cliente', 'lat' => '', 'long' => '', 'date' => now(),
            'total' => 60, 'totEnvio' => 0, 'order_state' => PurchaseOrder::STATE_PENDING,
        ]);
        $payment = OrderPayment::create([
            'order_id' => $order->id, 'store_id' => $store->id, 'method' => 'legacy_unknown',
            'terms' => 'prepaid', 'status' => 'pending', 'currency' => 'MXN',
            'products_amount' => 60, 'discount_amount' => 0, 'shipping_amount' => 0,
            'extra_amount' => 0, 'amount_due' => 60, 'amount_paid' => 0, 'amount_refunded' => 0,
        ]);
        OrderProviderTransaction::create([
            'payment_id' => $payment->id, 'store_id' => $store->id, 'order_id' => $order->id,
            'provider' => 'mercado_pago', 'provider_payment_id' => '6001',
            'amount' => '60.00', 'currency' => 'MXN', 'remote_status' => 'approved',
        ]);
        MercadoPagoAccount::create([
            'idLog' => $user->id, 'secretKey' => 'secret', 'publicKey' => 'public', 'merchantId' => '987',
        ]);
        config(['services.mercadopago.webhook_secret' => 'backfill-secret']);
        Http::fake([
            'api.mercadopago.com/v1/payments/6001' => Http::response([
                'external_reference' => $order->order, 'status' => 'approved',
                'transaction_amount' => 60, 'collector_id' => 987, 'currency_id' => 'MXN',
            ]),
        ]);

        $this->postWebhook('6001', 'rq-backfill', 'backfill-secret');

        $this->assertDatabaseCount('order_provider_transactions', 1);
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id, 'method' => 'mercado_pago', 'status' => 'pending',
        ]);
        $this->assertNotNull(OrderPayment::where('order_id', $order->id)->value('frozen_at'));
        $this->assertDatabaseHas('ordencompra', ['id' => $order->id, 'order_state' => PurchaseOrder::STATE_PENDING]);
        $this->assertDatabaseCount('order_audit_events', 0);
    }

    public function test_confirm_payment_converts_legacy_unknown_to_cash_with_reference_and_locks_it_one_shot(): void
    {
        [$owner, $store] = $this->signInStore('legacy-cash@example.test');
        $token = 'legacy-cash-cart';
        $product = $this->createProduct($store->createdby, ['number' => '60'], 3);
        $order = $this->legacyOrder($store, $token, 'LEGACY-CASH', 60, $product->id);

        $this->withHeaders(['X-Cart-Token' => $token, 'Idempotency-Key' => 'legacy-cash-1'])
            ->putJson("/api/v1/orders/{$order->id}/confirm-payment", [
                'method' => 'cash', 'reference' => 'LEGACY-REF-1',
            ])->assertOk()
            ->assertJsonPath('data.order', 'LEGACY-CASH')
            ->assertJsonPath('data.status', '3')
            ->assertJsonPath('data.idempotent', false);
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id, 'method' => 'cash', 'status' => 'paid', 'cash_reference' => 'LEGACY-REF-1',
        ]);
        $this->assertDatabaseHas('cart', ['orderC' => $order->order, 'status' => '3']);

        // One-shot: un segundo intento con otro metodo es 422, no re-canoniza.
        $this->withHeaders(['X-Cart-Token' => $token, 'Idempotency-Key' => 'legacy-cash-2'])
            ->putJson("/api/v1/orders/{$order->id}/confirm-payment", [
                'method' => 'bank_transfer', 'reference' => 'LEGACY-REF-2',
            ])->assertStatus(422)->assertJsonPath('message', 'El metodo de cobro no puede cambiarse.');
    }

    public function test_confirm_payment_rejects_legacy_unknown_without_a_declared_method(): void
    {
        [$owner, $store] = $this->signInStore('legacy-no-method@example.test');
        $token = 'legacy-no-method-cart';
        $product = $this->createProduct($store->createdby, ['number' => '60'], 3);
        $order = $this->legacyOrder($store, $token, 'LEGACY-NO-METHOD', 60, $product->id);

        $this->withHeader('Idempotency-Key', 'legacy-no-method')
            ->putJson("/api/v1/orders/{$order->id}/confirm-payment", ['reference' => 'NOPE'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Debes declarar el metodo de cobro real: cash, bank_transfer o cash_on_delivery.');
    }

    public function test_clean_data_revalidates_finance_protection_inside_the_transaction(): void
    {
        [, $store] = $this->signInStore('clean-data-recheck@example.test');
        $this->order($store, 'CLEAN-DATA-RECHECK', 10);
        $admin = User::create(['name' => 'clean-recheck@example.test', 'keyvalue' => 'test', 'type' => '0', 'active' => true]);
        $admin->assignRole(Role::findOrCreate('super-admin', 'web'));
        Sanctum::actingAs($admin, ['*']);

        // Infiltrar una fila financiera mientras corren los guards intra-
        // transaccion: los checks posteriores DENTRO de la misma transaccion
        // deben detectarla y abortar la limpieza completa (sin borrados).
        $injected = false;
        DB::listen(function ($query) use (&$injected) {
            if ($injected || ! str_contains($query->sql, 'order_payment_proofs')) {
                return;
            }
            $injected = true;
            // order_id inexistente: order_payments es UNICO por orden y la fila
            // original (orden 1) ya ocupa su orden.
            DB::table('order_payments')->insert([
                'order_id' => 999999, 'store_id' => 1, 'method' => 'cash', 'terms' => 'prepaid',
                'status' => 'pending', 'currency' => 'MXN', 'products_amount' => 0,
                'discount_amount' => 0, 'shipping_amount' => 0, 'extra_amount' => 0,
                'amount_due' => 1, 'amount_paid' => 0, 'amount_refunded' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->postJson('/api/v1/admin/clean-data')
            ->assertConflict()
            ->assertJsonPath('message', 'La limpieza se rechazo porque existen registros financieros o de auditoria protegidos.');
        // Nada se borro y la fila infiltrada tambien rodo atras (atomicidad).
        $this->assertDatabaseHas('liks', ['serial' => $store->serial]);
        $this->assertDatabaseCount('order_payments', 1);
    }

    public function test_proof_uploads_are_rate_limited_aggregate_by_ip(): void
    {
        [, $store] = $this->signInStore('proof-throttle-ip@example.test');
        Storage::fake('payment-proofs');

        foreach (range(1, 20) as $attempt) {
            $this->withHeaders([
                'X-Cart-Token' => 'ip-throttle-cart',
                'Idempotency-Key' => "ip-throttle-{$attempt}",
            ])->post("/api/v1/stores/{$store->serial}/orders/NO-SUCH-ORDER-{$attempt}/transfer-proof", [
                'reference' => "IP-{$attempt}",
                'proof' => $this->png("ip-{$attempt}.png"),
            ])->assertNotFound();
        }

        // La capa por IP (20/min) se agota aunque las ordenes roten.
        $this->withHeaders([
            'X-Cart-Token' => 'ip-throttle-cart',
            'Idempotency-Key' => 'ip-throttle-21',
        ])->post("/api/v1/stores/{$store->serial}/orders/NO-SUCH-ORDER-21/transfer-proof", [
            'reference' => 'IP-21',
            'proof' => $this->png('ip-21.png'),
        ])->assertStatus(429);
    }

    public function test_refund_requires_the_verify_permission(): void
    {
        // Dueno SIN orders.refunds.verify: el middleware corta 403 antes de
        // tocar la orden.
        $email = 'refund-locked@example.test';
        $user = User::create(['name' => $email, 'keyvalue' => 'test', 'type' => '1', 'active' => true]);
        $store = Store::create(['serial' => 'REFUND-LOCKED', 'createdby' => $email]);
        Permission::findOrCreate('orders.refunds.verify', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user, ['*']);
        $order = $this->order($store, 'REFUND-LOCKED-ORDER', 10);

        $this->withHeader('Idempotency-Key', 'refund-locked')
            ->postJson("/api/v1/orders/{$order->id}/refund", [])
            ->assertForbidden();
        $this->assertDatabaseCount('order_audit_events', 0);
    }

    private function legacyOrder(Store $store, string $token, string $reference, float $total, int $productId): PurchaseOrder
    {
        $order = PurchaseOrder::create([
            'order' => $reference, 'serial' => $store->serial, 'session' => $token,
            'tel' => '5551112222', 'nombre' => 'Cliente', 'lat' => '', 'long' => '', 'date' => now(),
            'total' => $total, 'totEnvio' => 0, 'order_state' => PurchaseOrder::STATE_PENDING,
        ]);
        Cart::create([
            'product' => $productId, 'price' => $total, 'dom' => $store->createdby, 'user' => $token,
            'variation' => $store->serial, 'cant' => 1, 'status' => 0, 'orderC' => $order->order,
        ]);
        OrderPayment::create([
            'order_id' => $order->id, 'store_id' => $store->id, 'method' => 'legacy_unknown',
            'terms' => 'prepaid', 'status' => 'pending', 'currency' => 'MXN',
            'products_amount' => $total, 'discount_amount' => 0, 'shipping_amount' => 0,
            'extra_amount' => 0, 'amount_due' => $total, 'amount_paid' => 0, 'amount_refunded' => 0,
        ]);

        return $order;
    }

    private function mpBaseline(PurchaseOrder $order, string $token): void
    {
        Cart::create([
            'product' => 1, 'price' => 80, 'dom' => $order->serial,
            'user' => $token, 'variation' => $order->serial, 'cant' => 1, 'status' => 2, 'orderC' => $order->order,
        ]);
        OrderPayment::where('order_id', $order->id)->update([
            'method' => 'mercado_pago', 'status' => 'pending', 'products_amount' => 80, 'amount_due' => 80,
        ]);
        MercadoPagoPayment::create(['orderP' => $order->order, 'status' => 0, 'preference' => '', 'fecha' => null]);
    }

    private function postWebhook(string $paymentId, string $requestId, string $secret): void
    {
        $manifest = "id:{$paymentId};request-id:{$requestId};ts:100;";
        $hash = hash_hmac('sha256', $manifest, $secret);
        $this->withHeaders(['X-Request-Id' => $requestId, 'X-Signature' => "ts=100,v1={$hash}"])
            ->postJson('/api/v1/payments/webhook', [
                'type' => 'payment', 'user_id' => 987, 'data' => ['id' => $paymentId],
            ])
            ->assertOk();
    }

    private function order(Store $store, string $reference, float $total): PurchaseOrder
    {
        $order = PurchaseOrder::create([
            'order' => $reference, 'serial' => $store->serial, 'session' => 'fase8a-'.$reference,
            'tel' => '5551112222', 'nombre' => 'Cliente', 'lat' => '', 'long' => '', 'date' => now(),
            'total' => $total, 'totEnvio' => 0, 'order_state' => PurchaseOrder::STATE_PENDING,
        ]);
        OrderPayment::create([
            'order_id' => $order->id, 'store_id' => $store->id, 'method' => 'mercado_pago',
            'terms' => 'prepaid', 'status' => 'pending', 'currency' => 'MXN',
            'products_amount' => $total, 'discount_amount' => 0, 'shipping_amount' => 0,
            'extra_amount' => 0, 'amount_due' => $total, 'amount_paid' => 0, 'amount_refunded' => 0,
            'frozen_at' => now(),
        ]);

        return $order;
    }

    private function checkout($store, string $token, string $method, string $delivery, float $price, int $quantity = 1, int $stock = 5): PurchaseOrder
    {
        $product = $this->createProduct($store->createdby, ['number' => (string) $price], $stock);
        Cart::create([
            'product' => $product->id, 'price' => $price * $quantity, 'dom' => $store->createdby,
            'user' => $token, 'variation' => $store->serial, 'cant' => $quantity, 'status' => 0,
        ]);
        $response = $this->withHeaders(['X-Cart-Token' => $token, 'Idempotency-Key' => 'checkout-'.$token])
            ->postJson("/api/v1/stores/{$store->serial}/checkout", [
                'nombre' => 'Cliente', 'telefono' => '5551112222',
                'tipo_envio' => $delivery, 'payment_method' => $method,
                'direccion' => $delivery === 'pickup' ? null : 'Direccion local',
            ])
            ->assertCreated();

        return PurchaseOrder::findOrFail($response->json('data.id'));
    }

    private function mpOrderWithItem(Store $store, int $productId, string $reference, string $token, float $price): PurchaseOrder
    {
        $order = PurchaseOrder::create([
            'order' => $reference, 'serial' => $store->serial, 'session' => $token,
            'tel' => '5551112222', 'nombre' => 'Cliente', 'lat' => '', 'long' => '', 'date' => now(),
            'total' => $price, 'totEnvio' => 0, 'order_state' => PurchaseOrder::STATE_PENDING,
        ]);
        Cart::create([
            'product' => $productId, 'price' => $price, 'dom' => $store->createdby, 'user' => $token,
            'variation' => $store->serial, 'cant' => 1, 'status' => 2, 'orderC' => $reference,
        ]);
        OrderPayment::create([
            'order_id' => $order->id, 'store_id' => $store->id, 'method' => 'mercado_pago',
            'terms' => 'prepaid', 'status' => 'pending', 'currency' => 'MXN',
            'products_amount' => $price, 'discount_amount' => 0, 'shipping_amount' => 0,
            'extra_amount' => 0, 'amount_due' => $price, 'amount_paid' => 0, 'amount_refunded' => 0,
        ]);

        return $order;
    }

    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_09_22_000003_add_canonical_order_finance_and_returns.php');
    }

    private function resetToPre8aBaseline(): void
    {
        foreach (['order_provider_transactions', 'order_payment_proofs', 'order_returns', 'order_audit_events', 'order_payments'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('ordencompra', fn (Blueprint $table) => $table->dropColumn('delivery_type'));
        Schema::table('ordenenvio', fn (Blueprint $table) => $table->dropColumn(['departure_state', 'departed_at']));
        Schema::drop('mercadopagocuentas');
        Schema::create('mercadopagocuentas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idLog')->unique();
            $table->text('secretKey');
            $table->text('publicKey');
            $table->string('merchantId')->nullable();
        });
    }
}
