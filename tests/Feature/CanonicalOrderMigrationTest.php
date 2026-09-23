<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\InteractsWithSalesSchema;
use Tests\TestCase;

class CanonicalOrderMigrationTest extends TestCase
{
    use InteractsWithSalesSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesSchema();
        $this->resetToPre8aBaseline();
    }

    public function test_canonical_migration_up_is_retryable_on_a_complete_schema(): void
    {
        $storeId = DB::table('liks')->insertGetId(['serial' => 'LEGACY', 'createdby' => 'legacy@example.test']);
        $orderId = DB::table('ordencompra')->insertGetId($this->legacyOrder('LEGACY-PAID', 'LEGACY', '25.00', '5.00'));
        DB::table('cart')->insert([
            'price' => '25.00', 'variation' => 'LEGACY', 'orderC' => 'LEGACY-PAID', 'status' => 3,
        ]);
        DB::table('mercadopago')->insert([
            'orderP' => 'LEGACY-PAID', 'status' => 1, 'preference' => 'PREF-LEGACY', 'payment_id' => 777,
        ]);
        $pendingId = DB::table('ordencompra')->insertGetId(array_merge(
            $this->legacyOrder('LEGACY-NO-CART', 'LEGACY', '10.00', '0.00'),
            ['order_state' => null],
        ));
        $migration = $this->migration();
        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumns('ordencompra', ['delivery_type']));
        $this->assertTrue(Schema::hasColumns('ordenenvio', ['departure_state', 'departed_at']));
        foreach (['order_payments', 'order_provider_transactions', 'order_payment_proofs', 'order_returns', 'order_audit_events'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $orderId,
            'store_id' => $storeId,
            'method' => 'legacy_unknown',
            'status' => 'paid',
            'amount_due' => 30,
            'amount_paid' => 30,
        ]);
        $this->assertDatabaseHas('order_provider_transactions', [
            'order_id' => $orderId,
            'provider' => 'mercado_pago',
            'provider_payment_id' => '777',
            'amount' => 30,
        ]);
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $pendingId,
            'method' => 'legacy_unknown',
            'status' => 'pending',
            'products_amount' => 10,
            'amount_due' => 10,
        ]);
        $this->assertDatabaseCount('order_payments', 2);
        $this->assertDatabaseCount('order_provider_transactions', 1);
    }

    public function test_canonical_migration_preflight_rejects_duplicate_merchants_without_deleting_rows(): void
    {
        DB::table('mercadopagocuentas')->insert([
            ['idLog' => 1, 'secretKey' => 'one', 'publicKey' => 'one', 'merchantId' => 'duplicate'],
            ['idLog' => 2, 'secretKey' => 'two', 'publicKey' => 'two', 'merchantId' => 'duplicate'],
        ]);

        try {
            $this->migration()->up();
            $this->fail('The preflight should reject duplicate merchant IDs.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('duplicate MercadoPago merchant', $exception->getMessage());
        }
        $this->assertDatabaseCount('mercadopagocuentas', 2);
    }

    public function test_canonical_migration_rejects_money_with_more_than_two_decimals_before_ddl(): void
    {
        DB::table('liks')->insert(['serial' => 'BAD-MONEY', 'createdby' => 'bad-money@example.test']);
        DB::table('ordencompra')->insert($this->legacyOrder('BAD-MONEY-ORDER', 'BAD-MONEY', '1.001', '0.00'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid monetary value');
        $this->migration()->up();
    }

    public function test_canonical_migration_down_refuses_to_delete_financial_or_audit_rows(): void
    {
        $this->migration()->up();
        DB::table('order_audit_events')->insert([
            'order_id' => 999,
            'store_id' => 999,
            'actor_type' => 'system',
            'event_type' => 'test',
            'event_key' => hash('sha256', 'migration-down-test'),
            'request_hash' => hash('sha256', 'request'),
            'response_status' => 200,
            'created_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing destructive rollback');
        $this->migration()->down();
    }

    public function test_permissions_migration_provisions_finance_dispatch_and_mercado_pago_permissions(): void
    {
        $migration = require database_path('migrations/2026_09_22_000004_add_order_finance_permissions.php');
        $migration->up();
        $migration->up();

        $this->assertDatabaseHas('permissions', ['name' => 'orders.dispatch', 'guard_name' => 'web']);
        $this->assertDatabaseHas('permissions', ['name' => 'payments.mercado-pago.manage', 'guard_name' => 'web']);
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

    private function legacyOrder(string $reference, string $serial, string $total, string $shipping): array
    {
        return [
            'order' => $reference,
            'tel' => '555',
            'serial' => $serial,
            'session' => 'legacy-session',
            'lat' => '0',
            'long' => '0',
            'total' => $total,
            'totEnvio' => $shipping,
            'nombre' => 'Legacy',
            'date' => '2026-01-01',
            'loyalty_discount' => '0.00',
            'order_state' => 'paid',
        ];
    }
}
