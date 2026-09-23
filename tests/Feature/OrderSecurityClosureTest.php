<?php

namespace Tests\Feature;

use App\Models\OrderPayment;
use App\Models\PurchaseOrder;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\InteractsWithSalesSchema;
use Tests\TestCase;

class OrderSecurityClosureTest extends TestCase
{
    use InteractsWithSalesSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesSchema();
    }

    public function test_my_orders_is_closed_without_reading_customer_data(): void
    {
        $this->signInStore('history-closed@example.test');

        $this->getJson('/api/v1/my-orders')
            ->assertServiceUnavailable()
            ->assertJsonMissingPath('data');
    }

    public function test_generic_multiple_upload_is_fail_closed_for_executable_content(): void
    {
        $this->signInStore('upload-closed@example.test');

        $this->post('/api/v1/upload/images', [
            'files' => [UploadedFile::fake()->create('payload.php', 1, 'application/x-php')],
        ])->assertServiceUnavailable();
    }

    public function test_pure_superadmin_can_audit_but_cannot_mutate_store_finances(): void
    {
        [, $store] = $this->signInStore('audit-store@example.test');
        $order = PurchaseOrder::create([
            'order' => 'AUDIT-ONLY',
            'serial' => $store->serial,
            'session' => 'audit-cart',
            'tel' => '5551112222',
            'nombre' => 'Cliente',
            'lat' => '0',
            'long' => '0',
            'date' => now(),
            'total' => 10,
            'totEnvio' => 0,
            'order_state' => PurchaseOrder::STATE_PENDING,
        ]);
        $admin = User::create(['name' => 'pure-admin@example.test', 'keyvalue' => 'test', 'type' => '0', 'active' => true]);
        $role = Role::findOrCreate('super-admin', 'web');
        $role->givePermissionTo([
            Permission::findOrCreate('orders.read', 'web'),
            Permission::findOrCreate('orders.payments.audit', 'web'),
        ]);
        $admin->assignRole($role);
        Sanctum::actingAs($admin, ['*']);

        $this->getJson("/api/v1/admin/orders/{$order->id}/audit")->assertOk();
        $this->withHeader('Idempotency-Key', 'admin-must-not-pay')
            ->putJson("/api/v1/orders/{$order->id}/confirm-payment")
            ->assertForbidden();
        $this->assertDatabaseHas('ordencompra', ['id' => $order->id, 'order_state' => PurchaseOrder::STATE_PENDING]);
    }

    public function test_mercado_pago_management_requires_explicit_permission_for_every_actor_type(): void
    {
        foreach ([
            ['owner-no-mp@example.test', '1'],
            ['rider-no-mp@example.test', '3'],
            ['customer-no-mp@example.test', '4'],
        ] as [$email, $type]) {
            $user = User::create(['name' => $email, 'keyvalue' => 'test', 'type' => $type, 'active' => true]);
            if ($type === '1') {
                Store::create(['serial' => 'NO-MP-PERMISSION', 'createdby' => $email]);
            }
            Sanctum::actingAs($user, ['*']);
            $this->getJson('/api/v1/payments/account')->assertForbidden();
        }
    }

    public function test_dual_role_superadmin_cannot_mutate_store_finance_or_mercado_pago(): void
    {
        [$owner, $store] = $this->signInStore('dual-admin@example.test');
        $owner->assignRole(Role::findOrCreate('super-admin', 'web'));
        Sanctum::actingAs($owner, ['*']);
        Http::fake();

        $this->postJson('/api/v1/payments/account', [
            'secret_key' => 'must-not-be-verified',
            'public_key' => 'public',
        ])->assertForbidden();
        Http::assertNothingSent();

        $order = PurchaseOrder::create([
            'order' => 'DUAL-ADMIN', 'serial' => $store->serial, 'session' => 'dual', 'tel' => '555',
            'nombre' => 'Cliente', 'lat' => '0', 'long' => '0', 'date' => now(), 'total' => 10,
            'totEnvio' => 0, 'order_state' => PurchaseOrder::STATE_PENDING,
        ]);
        $this->withHeader('Idempotency-Key', 'dual-admin-pay')
            ->putJson("/api/v1/orders/{$order->id}/confirm-payment")
            ->assertForbidden();
        OrderPayment::create([
            'order_id' => $order->id, 'store_id' => $store->id, 'method' => 'legacy_unknown',
            'terms' => 'prepaid', 'status' => 'pending', 'currency' => 'MXN',
            'products_amount' => 10, 'discount_amount' => 0, 'shipping_amount' => 0,
            'extra_amount' => 0, 'amount_due' => 10, 'amount_paid' => 0, 'amount_refunded' => 0,
        ]);
        $this->postJson('/api/v1/admin/clean-data')->assertConflict();
        $this->assertDatabaseHas('ordencompra', ['id' => $order->id]);
    }

    public function test_owner_order_summary_excludes_financial_secrets(): void
    {
        [, $store] = $this->signInStore('safe-summary@example.test');
        $order = PurchaseOrder::create([
            'order' => 'SAFE-SUMMARY', 'serial' => $store->serial, 'session' => 'safe', 'tel' => '555',
            'nombre' => 'Cliente', 'lat' => '0', 'long' => '0', 'date' => now(), 'total' => 10,
            'totEnvio' => 0, 'order_state' => PurchaseOrder::STATE_PENDING,
        ]);
        OrderPayment::create([
            'order_id' => $order->id, 'store_id' => $store->id, 'method' => 'bank_transfer',
            'terms' => 'prepaid', 'status' => 'proof_submitted', 'currency' => 'MXN',
            'products_amount' => 10, 'discount_amount' => 0, 'shipping_amount' => 0,
            'extra_amount' => 0, 'amount_due' => 10, 'amount_paid' => 0, 'amount_refunded' => 0,
            'bank_reference' => 'PRIVATE-BANK-REFERENCE', 'provider_payment_id' => 'PRIVATE-PROVIDER-ID',
        ]);

        $response = $this->getJson("/api/v1/orders/{$order->id}")->assertOk();
        $response->assertJsonPath('data.payment.status', 'proof_submitted');
        $this->assertArrayNotHasKey('bank_reference', $response->json('data.payment'));
        $this->assertArrayNotHasKey('provider_payment_id', $response->json('data.payment'));
        $this->assertStringNotContainsString('PRIVATE-', $response->getContent());
    }
}
