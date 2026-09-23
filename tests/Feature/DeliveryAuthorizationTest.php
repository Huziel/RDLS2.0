<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\DeliveryLink;
use App\Models\DeliveryPhoto;
use App\Models\DeliveryProfile;
use App\Models\DeliveryWallet;
use App\Models\PurchaseOrder;
use App\Models\ShippingOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Support\InteractsWithSalesSchema;
use Tests\TestCase;

class DeliveryAuthorizationTest extends TestCase
{
    use InteractsWithSalesSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesSchema();
        $this->buildDeliverySchema();
    }

    public function test_store_owner_cannot_globally_verify_a_deliverer(): void
    {
        [, $store] = $this->signInStore('owner-verify@example.test', ['delivery.manage', 'delivery.block']);
        $rider = $this->rider($store, verified: false);
        $link = DeliveryLink::where('deliveryMan', $rider->id)->firstOrFail();

        $this->putJson("/api/v1/delivery/linked/{$link->id}/verify")
            ->assertNotFound()
            ->assertHeader('content-type', 'application/json');
        $this->putJson("/api/v1/admin/deliverers/{$rider->id}/verify")->assertForbidden();

        $this->assertDatabaseHas('datospersonales', ['idLog' => $rider->id, 'verificado' => '0']);
    }

    public function test_linked_deliverers_hide_identity_and_address_documents(): void
    {
        [, $store] = $this->signInStore('owner-documents@example.test', ['delivery.manage']);
        $rider = $this->rider($store, profile: [
            'fotoID' => 'private-id-document',
            'fotoDomicilio' => 'private-address-document',
        ]);
        DeliveryPhoto::create(['idUser' => $rider->id, 'picture' => 'profile-picture']);

        $response = $this->getJson('/api/v1/delivery/linked')->assertOk();
        $profile = $response->json('data.0.profile');

        $this->assertArrayNotHasKey('foto_id', $profile);
        $this->assertArrayNotHasKey('foto_domicilio', $profile);
        $this->assertStringNotContainsString('private-id-document', $response->getContent());
        $this->assertStringNotContainsString('private-address-document', $response->getContent());
    }

    public function test_cross_tenant_emission_is_not_found_and_does_not_mutate(): void
    {
        $this->signInStore('owner-tenant@example.test', ['delivery.manage']);
        $foreignStore = Store::create(['serial' => 'FOREIGN-DELIVERY', 'createdby' => 'foreign-delivery@example.test']);
        $foreignOrder = $this->order($foreignStore, 'FOREIGN-ORDER');

        $this->postJson("/api/v1/orders/{$foreignOrder->id}/emit-shipping", [
            'assignment_mode' => 'pool',
        ])->assertNotFound();

        $this->assertDatabaseCount('ordenenvio', 0);
        $this->assertDatabaseHas('ordencompra', [
            'id' => $foreignOrder->id,
            'lat' => '19.4326',
            'long' => '-99.1332',
        ]);
    }

    public function test_direct_emission_rejects_an_ineligible_rider_without_side_effects(): void
    {
        [, $store] = $this->signInStore('owner-direct@example.test', ['delivery.manage']);
        $order = $this->order($store, 'DIRECT-INELIGIBLE');
        $rider = $this->rider($store, verified: false);

        $this->postJson("/api/v1/orders/{$order->id}/emit-shipping", [
            'assignment_mode' => 'pool',
            'delivery_id' => $rider->id,
        ])->assertUnprocessable();
        $this->postJson("/api/v1/orders/{$order->id}/emit-shipping", [
            'assignment_mode' => 'direct',
            'delivery_id' => $rider->id,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('ordenenvio', 0);
        $this->assertDatabaseCount('verificacion', 0);
    }

    public function test_pool_order_can_only_be_accepted_by_an_eligible_linked_rider(): void
    {
        [, $store] = $this->signInStore('owner-pool@example.test', ['delivery.manage']);
        $order = $this->order($store, 'POOL-ELIGIBILITY');
        $shippingId = $this->emitPool($order);
        $blocked = $this->rider($store, blocked: true);
        $eligible = $this->rider($store, email: 'eligible-pool@example.test');

        Sanctum::actingAs($eligible, ['*']);
        $available = $this->getJson('/api/v1/delivery/available-orders')->assertOk();
        $available->assertJsonStructure(['data' => [[
            'shipping_id', 'store_id', 'store' => ['serial', 'name'], 'fecha', 'assignment_mode',
        ]], 'meta']);
        foreach (['cliente', 'telefono', 'order_id', 'total', 'lat', 'lng', 'direccion', 'payment'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $available->json('data.0'));
        }

        Sanctum::actingAs($blocked, ['*']);
        $this->postJson("/api/v1/delivery/orders/{$shippingId}/accept")->assertForbidden();
        $this->assertDatabaseHas('ordenenvio', ['id' => $shippingId, 'delivery' => null, 'status' => '0']);

        Sanctum::actingAs($eligible, ['*']);
        $response = $this->postJson("/api/v1/delivery/orders/{$shippingId}/accept")
            ->assertOk()
            ->assertJsonPath('data.delivery_id', $eligible->id)
            ->assertJsonPath('idempotent', false);

        $this->assertArrayNotHasKey('code', $response->json('data'));
        $this->assertDatabaseCount('verificacion', 0);
    }

    public function test_sequential_pool_acceptance_has_one_winner_and_is_idempotent_for_that_rider(): void
    {
        [, $store] = $this->signInStore('owner-race@example.test', ['delivery.manage']);
        $order = $this->order($store, 'POOL-RACE');
        $shippingId = $this->emitPool($order);
        $winner = $this->rider($store, email: 'winner@example.test');
        $loser = $this->rider($store, email: 'loser@example.test');

        Sanctum::actingAs($winner, ['*']);
        $this->postJson("/api/v1/delivery/orders/{$shippingId}/accept")
            ->assertOk()
            ->assertJsonPath('idempotent', false);
        $this->postJson("/api/v1/delivery/orders/{$shippingId}/accept")
            ->assertOk()
            ->assertJsonPath('idempotent', true);

        Sanctum::actingAs($loser, ['*']);
        $this->postJson("/api/v1/delivery/orders/{$shippingId}/accept")->assertConflict();

        $this->assertDatabaseHas('ordenenvio', [
            'id' => $shippingId,
            'delivery' => $winner->id,
            'status' => '1',
        ]);
        $this->assertDatabaseCount('ordenenvio', 1);
    }

    public function test_owner_and_rider_responses_never_expose_legacy_verification_codes(): void
    {
        [, $store] = $this->signInStore('owner-no-otp@example.test', ['delivery.manage']);
        $order = $this->order($store, 'NO-OTP');
        $rider = $this->rider($store);
        $shipping = ShippingOrder::create([
            'tienda' => $store->id,
            'delivery' => $rider->id,
            'ordenCompra' => $order->id,
            'fechaIn' => now()->format('Y-m-d H:i:s'),
            'status' => '1',
            'assignment_mode' => 'direct',
        ]);
        VerificationCode::create(['orderC' => $order->order, 'code' => '123']);

        $ownerResponse = $this->getJson("/api/v1/orders/{$order->id}")->assertOk();
        $this->assertArrayNotHasKey('verification_code', $ownerResponse->json('data'));
        $this->assertStringNotContainsString('123', $ownerResponse->getContent());

        Sanctum::actingAs($rider, ['*']);
        $riderResponse = $this->getJson('/api/v1/delivery/active-order')->assertOk();
        $this->assertArrayNotHasKey('code', $riderResponse->json('data'));
        $this->assertSame($shipping->id, $riderResponse->json('data.id'));
        $this->getJson('/api/v1/delivery/track')->assertNotFound();
    }

    public function test_completion_is_fail_closed_and_does_not_mutate_wallet_or_order(): void
    {
        [, $store] = $this->signInStore('owner-complete@example.test', ['delivery.manage']);
        $order = $this->order($store, 'COMPLETE-BLOCKED', 25);
        Cart::create($this->cartData($store, $order, '5'));
        $rider = $this->rider($store);
        $shipping = ShippingOrder::create([
            'tienda' => $store->id,
            'delivery' => $rider->id,
            'ordenCompra' => $order->id,
            'fechaIn' => now()->format('Y-m-d H:i:s'),
            'status' => '1',
            'assignment_mode' => 'direct',
        ]);
        DeliveryWallet::create(['idLog' => $rider->id, 'cant' => 10, 'time' => now()->format('Y-m-d H:i:s')]);
        VerificationCode::create(['orderC' => $order->order, 'code' => '123']);

        Sanctum::actingAs($rider, ['*']);
        $this->postJson("/api/v1/delivery/orders/{$shipping->id}/complete", ['code' => '123'])
            ->assertServiceUnavailable();

        $this->assertDatabaseHas('ordenenvio', ['id' => $shipping->id, 'status' => '1']);
        $this->assertDatabaseHas('cart', ['orderC' => $order->order, 'status' => '5']);
        $this->assertDatabaseHas('wallet', ['idLog' => $rider->id, 'cant' => 10]);
        $this->assertDatabaseCount('wallet', 1);
    }

    public function test_direct_assignment_cannot_return_to_pool_but_accepted_pool_can(): void
    {
        [, $store] = $this->signInStore('owner-release@example.test', ['delivery.manage']);
        $rider = $this->rider($store);
        $directOrder = $this->order($store, 'DIRECT-NO-POOL');
        $poolOrder = $this->order($store, 'POOL-RELEASE');
        $direct = ShippingOrder::create([
            'tienda' => $store->id,
            'delivery' => $rider->id,
            'ordenCompra' => $directOrder->id,
            'fechaIn' => now()->format('Y-m-d H:i:s'),
            'status' => '1',
            'assignment_mode' => 'direct',
        ]);
        $pool = ShippingOrder::create([
            'tienda' => $store->id,
            'delivery' => $rider->id,
            'ordenCompra' => $poolOrder->id,
            'fechaIn' => now()->format('Y-m-d H:i:s'),
            'status' => '1',
            'assignment_mode' => 'pool',
        ]);

        Sanctum::actingAs($rider, ['*']);
        $this->postJson("/api/v1/delivery/orders/{$direct->id}/cancel")->assertConflict();
        $this->assertDatabaseHas('ordenenvio', ['id' => $direct->id, 'delivery' => $rider->id, 'status' => '1']);

        $this->postJson("/api/v1/delivery/orders/{$pool->id}/cancel")->assertOk();
        $this->assertDatabaseHas('ordenenvio', ['id' => $pool->id, 'delivery' => null, 'status' => '0']);
    }

    public function test_assignment_and_acceptance_do_not_mark_departure_until_owner_dispatches(): void
    {
        [$owner, $store] = $this->signInStore('owner-dispatch@example.test', ['delivery.manage', 'orders.dispatch']);
        $order = $this->order($store, 'DIRECT-DISPATCH');
        Cart::create($this->cartData($store, $order, '4'));
        $rider = $this->rider($store);

        $shippingId = $this->postJson("/api/v1/orders/{$order->id}/emit-shipping", [
            'assignment_mode' => 'direct',
            'delivery_id' => $rider->id,
        ])->assertCreated()
            ->assertJsonPath('data.departure_state', 'not_departed')
            ->json('data.id');

        $this->assertDatabaseHas('cart', ['orderC' => $order->order, 'status' => 4]);
        $this->postJson("/api/v1/delivery/orders/{$shippingId}/dispatch")
            ->assertOk()
            ->assertJsonPath('data.departure_state', 'departed')
            ->assertJsonPath('data.status', '2');
        $this->postJson("/api/v1/delivery/orders/{$shippingId}/dispatch")
            ->assertOk()
            ->assertJsonPath('idempotent', true);
        $this->assertDatabaseHas('cart', ['orderC' => $order->order, 'status' => 5]);

        Sanctum::actingAs($rider, ['*']);
        $this->postJson("/api/v1/delivery/orders/{$shippingId}/cancel")->assertConflict();

        Sanctum::actingAs($owner, ['*']);
        $this->withHeader('Idempotency-Key', 'cancel-after-dispatch')
            ->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.return_pending', true)
            ->assertJsonPath('data.restocked', false);
    }

    public function test_emission_is_idempotent_and_cancelled_orders_are_rejected(): void
    {
        [, $store] = $this->signInStore('owner-idempotent@example.test', ['delivery.manage']);
        $order = $this->order($store, 'EMIT-ONCE');

        $this->postJson("/api/v1/orders/{$order->id}/emit-shipping", ['assignment_mode' => 'pool'])
            ->assertCreated()
            ->assertJsonPath('idempotent', false);
        $this->postJson("/api/v1/orders/{$order->id}/emit-shipping", ['assignment_mode' => 'pool'])
            ->assertOk()
            ->assertJsonPath('idempotent', true);
        $this->assertDatabaseCount('ordenenvio', 1);

        $cancelled = $this->order($store, 'CANCELLED-EMIT');
        $cancelled->update(['order_state' => PurchaseOrder::STATE_CANCELLED]);
        $this->postJson("/api/v1/orders/{$cancelled->id}/emit-shipping", ['assignment_mode' => 'pool'])
            ->assertConflict();
        $this->assertDatabaseCount('ordenenvio', 1);
        $this->assertDatabaseCount('verificacion', 0);
    }

    private function emitPool(PurchaseOrder $order): int
    {
        return $this->postJson("/api/v1/orders/{$order->id}/emit-shipping", [
            'assignment_mode' => 'pool',
        ])->assertCreated()->json('data.id');
    }

    private function rider(
        Store $store,
        bool $verified = true,
        bool $blocked = false,
        string $email = 'rider@example.test',
        array $profile = [],
    ): User {
        $user = User::create([
            'name' => $email,
            'keyvalue' => 'test',
            'type' => '3',
            'active' => true,
        ]);
        $user->assignRole(Role::findOrCreate('deliver', 'web'));

        DeliveryProfile::create(array_merge([
            'idLog' => $user->id,
            'nombre' => 'Repartidor',
            'apellidoPaterno' => 'Seguro',
            'apellidoMaterno' => 'Local',
            'verificado' => $verified ? '1' : '0',
        ], $profile));
        DeliveryLink::create([
            'deliveryMan' => $user->id,
            'store' => $store->id,
            'bloqueo' => $blocked ? '1' : '0',
        ]);

        return $user;
    }

    private function order(Store $store, string $reference, float $shipping = 0): PurchaseOrder
    {
        return PurchaseOrder::create([
            'order' => $reference,
            'serial' => $store->serial,
            'session' => 'delivery-cart-'.$reference,
            'tel' => '5551112222',
            'nombre' => 'Cliente',
            'lat' => '19.4326',
            'long' => '-99.1332',
            'date' => now()->format('Y-m-d H:i:s'),
            'total' => 100,
            'totEnvio' => $shipping,
            'order_state' => PurchaseOrder::STATE_PENDING,
        ]);
    }

    private function cartData(Store $store, PurchaseOrder $order, string $status): array
    {
        return [
            'product' => null,
            'price' => 100,
            'dom' => $store->createdby,
            'user' => $order->session,
            'variation' => $store->serial,
            'cant' => 1,
            'orderC' => $order->order,
            'status' => $status,
        ];
    }

    private function buildDeliverySchema(): void
    {
        if (! Schema::hasTable('ordenenvio')) {
            Schema::create('ordenenvio', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tienda');
                $table->unsignedBigInteger('delivery')->nullable();
                $table->unsignedBigInteger('ordenCompra');
                $table->dateTime('fechaIn')->nullable();
                $table->integer('status')->nullable();
                $table->string('assignment_mode', 10)->nullable();
                $table->index('ordenCompra');
            });
        }

        Schema::create('anexosdeliver', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('deliveryMan');
            $table->unsignedBigInteger('store');
            $table->integer('bloqueo')->default(0);
            $table->unique(['deliveryMan', 'store']);
        });

        Schema::create('datospersonales', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->nullable();
            $table->string('apellidoPaterno')->nullable();
            $table->string('apellidoMaterno')->nullable();
            $table->string('fechaNacimiento')->nullable();
            $table->string('placas')->nullable();
            $table->string('tipo')->nullable();
            $table->string('modelo')->nullable();
            $table->string('color')->nullable();
            $table->string('fotoPorfile')->nullable();
            $table->string('fotoID')->nullable();
            $table->string('fotoDomicilio')->nullable();
            $table->unsignedBigInteger('idLog')->unique();
            $table->integer('verificado')->default(0);
        });

        Schema::create('fotoporfile', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idUser')->unique();
            $table->string('picture')->nullable();
        });

        Schema::create('wallet', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idLog')->unique();
            $table->decimal('cant', 12, 2)->default(0);
            $table->dateTime('time')->nullable();
        });

        Schema::create('verificacion', function (Blueprint $table) {
            $table->id();
            $table->string('orderC');
            $table->string('code');
        });

        if (! Schema::hasTable('gastosextras')) {
            Schema::create('gastosextras', function (Blueprint $table) {
                $table->id();
                $table->string('orderP');
                $table->decimal('precio', 12, 2);
                $table->string('tipoCargo');
            });
        }
    }
}
