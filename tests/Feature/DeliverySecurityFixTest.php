<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\DeliveryLink;
use App\Models\DeliveryLocation;
use App\Models\DeliveryProfile;
use App\Models\OrderPayment;
use App\Models\PurchaseOrder;
use App\Models\ShippingOrder;
use App\Models\SiteSetting;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Support\InteractsWithSalesSchema;
use Tests\TestCase;

class DeliverySecurityFixTest extends TestCase
{
    use InteractsWithSalesSchema;

    private ?string $evidenceFixture = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesSchema();
        $this->buildDeliverySchema();
    }

    protected function tearDown(): void
    {
        foreach (glob(public_path('uploads/.tmp-6b-*.png')) ?: [] as $stale) {
            @unlink($stale);
        }
        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | P0-1 Evidencia manipulable
    |--------------------------------------------------------------------------
    */

    public function test_evidence_upload_requires_assigned_rider_active_shipping_and_stored_image(): void
    {
        [, $store] = $this->signInOwner('evidence-owner@example.test');
        $order = $this->order($store, 'EVID-OK');
        $rider = $this->rider($store);
        $this->directShipping($order, $rider, '1');

        Sanctum::actingAs($rider, ['*']);

        // Imagen valida del propio storage.
        $url = $this->makeEvidenceImage();
        $this->postJson('/api/v1/delivery/evidence', ['order_c' => $order->order, 'image_url' => $url])
            ->assertOk()
            ->assertJsonPath('message', 'Evidencia guardada.');
        $this->assertDatabaseHas('imageevidence', ['orderC' => $order->order, 'img' => $url]);

        // Reintento: cualquier evidencia previa -> 409 (fail closed).
        $this->postJson('/api/v1/delivery/evidence', ['order_c' => $order->order, 'image_url' => $url])
            ->assertConflict();
    }

    public function test_evidence_is_404_without_an_active_assignment_to_this_rider(): void
    {
        [, $store] = $this->signInOwner('evidence-404@example.test');
        $order = $this->order($store, 'EVID-404');
        $assigned = $this->rider($store, email: 'assigned@example.test');
        $other = $this->rider($store, email: 'other@example.test');
        $this->directShipping($order, $assigned, '1');

        Sanctum::actingAs($other, ['*']);
        $this->postJson('/api/v1/delivery/evidence', [
            'order_c' => $order->order,
            'image_url' => $this->makeEvidenceImage(),
        ])->assertNotFound();
        $this->assertDatabaseCount('imageevidence', 0);
    }

    public function test_evidence_is_rejected_when_shipping_is_not_active_or_order_is_unknown(): void
    {
        [, $store] = $this->signInOwner('evidence-status@example.test');
        $rider = $this->rider($store);

        $pending = $this->order($store, 'EVID-PENDING');
        ShippingOrder::create([
            'tienda' => $store->id,
            'delivery' => null,
            'ordenCompra' => $pending->id,
            'fechaIn' => now()->format('Y-m-d H:i:s'),
            'status' => '0',
            'assignment_mode' => 'pool',
        ]);

        $ghost = $this->order($store, 'EVID-GHOST');

        Sanctum::actingAs($rider, ['*']);
        $this->postJson('/api/v1/delivery/evidence', [
            'order_c' => $pending->order,
            'image_url' => $this->makeEvidenceImage(),
        ])->assertNotFound();
        $this->postJson('/api/v1/delivery/evidence', [
            'order_c' => $ghost->order,
            'image_url' => $this->makeEvidenceImage(),
        ])->assertNotFound();
        $this->assertDatabaseCount('imageevidence', 0);
    }

    public function test_evidence_rejects_external_or_missing_images(): void
    {
        [, $store] = $this->signInOwner('evidence-img@example.test');
        $order = $this->order($store, 'EVID-IMG');
        $rider = $this->rider($store);
        $this->directShipping($order, $rider, '1');

        Sanctum::actingAs($rider, ['*']);
        $this->postJson('/api/v1/delivery/evidence', [
            'order_c' => $order->order,
            'image_url' => 'https://evil.example/steal.png',
        ])->assertUnprocessable();
        $this->postJson('/api/v1/delivery/evidence', [
            'order_c' => $order->order,
            'image_url' => '/uploads/no-such-file-6b.png',
        ])->assertUnprocessable();
        $this->postJson('/api/v1/delivery/evidence', [
            'order_c' => $order->order,
            'image_url' => '/uploads/../.env',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('imageevidence', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | P0-2 Marca de pago legacy destruida
    |--------------------------------------------------------------------------
    */

    public function test_legacy_paid_order_survives_direct_emit_accept_and_release_without_restocking(): void
    {
        [, $store] = $this->signInOwner('legacy-paid@example.test');
        $product = $this->createProduct($store->createdby, ['number' => '6B'], 5);
        $rider = $this->rider($store);

        // Flujo pool: accept + release no tocan renglones pagados (status '3').
        $orderB = $this->order($store, 'LEGACY-PAID-B', orderState: null);
        $this->cartLine($store, $orderB, $product->id, '3');
        $shippingB = $this->emitPool($orderB);

        Sanctum::actingAs($rider, ['*']);
        $this->postJson("/api/v1/delivery/orders/{$shippingB}/accept")->assertOk();
        $orderB->refresh();
        $this->assertTrue($orderB->isPaid());
        $this->assertDatabaseHas('cart', ['orderC' => $orderB->order, 'status' => '3']);

        $this->postJson("/api/v1/delivery/orders/{$shippingB}/cancel")->assertOk();
        $orderB->refresh();
        $this->assertTrue($orderB->isPaid());
        $this->assertDatabaseHas('cart', ['orderC' => $orderB->order, 'status' => '3']);
        $this->assertDatabaseHas('stock', ['idProd' => $product->id, 'stock' => 5]);

        // Flujo direct sobre otra orden legacy pagada (rider sin entrega activa).
        Sanctum::actingAs($this->ownerUser('legacy-paid@example.test'), ['*']);
        $orderA = $this->order($store, 'LEGACY-PAID-A', orderState: null);
        $this->cartLine($store, $orderA, $product->id, '3');
        $this->postJson("/api/v1/orders/{$orderA->id}/emit-shipping", [
            'delivery_id' => $rider->id,
        ])->assertCreated()->assertJsonPath('data.assignment_mode', 'direct');

        $orderA->refresh();
        $this->assertTrue($orderA->isPaid());
        $this->assertDatabaseHas('cart', ['orderC' => $orderA->order, 'status' => '3']);
        $this->assertDatabaseHas('stock', ['idProd' => $product->id, 'stock' => 5]);

        // publicCancel sobre la orden legacy pagada -> 422 y nada de stock.
        $this->flushHeaders();
        $this->withHeader('X-Cart-Token', $orderA->session)
            ->postJson("/api/v1/stores/{$store->serial}/orders/{$orderA->order}/cancel")
            ->assertUnprocessable();
        $this->assertDatabaseHas('stock', ['idProd' => $product->id, 'stock' => 5]);
        $this->assertTrue($orderA->refresh()->isPaid());

        // El listado usa el estado canonico (isPaid), no el cart status solo.
        Sanctum::actingAs($this->ownerUser('legacy-paid@example.test'), ['*']);
        $list = $this->getJson('/api/v1/orders')->assertOk()->json('data');
        $paid = collect($list)->firstWhere('order', $orderA->order);
        $this->assertTrue($paid['paid']);
    }

    /*
    |--------------------------------------------------------------------------
    | P0-3 IDOR GPS
    |--------------------------------------------------------------------------
    */

    public function test_rider_cannot_read_another_riders_gps(): void
    {
        [, $store] = $this->signInOwner('gps-rider@example.test');
        $riderA = $this->rider($store, email: 'gps-a@example.test');
        $riderB = $this->rider($store, email: 'gps-b@example.test');
        DeliveryLocation::create(['idDeliver' => $riderA->id, 'latitud' => '19.1', 'longitud' => '-99.1', 'time' => now()->format('Y-m-d H:i:s')]);
        DeliveryLocation::create(['idDeliver' => $riderB->id, 'latitud' => '19.2', 'longitud' => '-99.2', 'time' => now()->format('Y-m-d H:i:s')]);

        Sanctum::actingAs($riderA, ['*']);
        $this->getJson("/api/v1/delivery/location/{$riderB->id}")->assertNotFound();
        $own = $this->getJson("/api/v1/delivery/location/{$riderA->id}")->assertOk();
        $this->assertSame('19.1', $own->json('data.latitud'));
    }

    public function test_owner_gps_requires_link_and_active_shipping_in_his_own_store(): void
    {
        [$ownerA] = $this->signInOwner('gps-owner-a@example.test');
        $storeA = Store::byOwner('gps-owner-a@example.test')->firstOrFail();
        $storeB = Store::create(['serial' => 'GPS-STORE-B', 'createdby' => 'gps-owner-b@example.test']);

        $riderForeign = $this->rider($storeB, email: 'gps-foreign@example.test');
        $riderLinked = $this->rider($storeA, email: 'gps-linked@example.test');
        $riderActive = $this->rider($storeA, email: 'gps-active@example.test');
        DeliveryLocation::create(['idDeliver' => $riderForeign->id, 'latitud' => '1', 'longitud' => '2', 'time' => now()->format('Y-m-d H:i:s')]);
        DeliveryLocation::create(['idDeliver' => $riderLinked->id, 'latitud' => '3', 'longitud' => '4', 'time' => now()->format('Y-m-d H:i:s')]);
        DeliveryLocation::create(['idDeliver' => $riderActive->id, 'latitud' => '5', 'longitud' => '6', 'time' => now()->format('Y-m-d H:i:s')]);

        // Vinculado a mi tienda pero sin entrega activa.
        $linkedOrder = $this->order($storeA, 'GPS-LINKED');
        $this->directShipping($linkedOrder, $riderLinked, '4');

        // Entrega activa de mi tienda.
        $activeOrder = $this->order($storeA, 'GPS-ACTIVE');
        $this->directShipping($activeOrder, $riderActive, '1');

        Sanctum::actingAs($ownerA, ['*']);
        $this->getJson("/api/v1/delivery/location/{$riderForeign->id}")->assertNotFound();
        $this->getJson("/api/v1/delivery/location/{$riderLinked->id}")->assertNotFound();
        $active = $this->getJson("/api/v1/delivery/location/{$riderActive->id}")->assertOk();
        $this->assertSame('5', $active->json('data.latitud'));
    }

    /*
    |--------------------------------------------------------------------------
    | P0-4 Verificacion tras cambio de documentos
    |--------------------------------------------------------------------------
    */

    public function test_changing_documents_revokes_verification_and_eligibility(): void
    {
        [, $store] = $this->signInOwner('docs-owner@example.test');
        $rider = $this->rider($store, email: 'docs-rider@example.test');

        Sanctum::actingAs($rider, ['*']);
        $this->putJson('/api/v1/delivery/profile', [
            'nombre' => 'Nuevo',
            'apellido_paterno' => 'Repartidor',
            'apellido_materno' => 'Cambiado',
            'fecha_nacimiento' => '1990-01-01',
            'foto_id' => '/uploads/new-id-document.png',
        ])->assertOk();

        $this->assertDatabaseHas('datospersonales', ['idLog' => $rider->id, 'verificado' => '0']);

        // La elegibilidad cae: el dueno ya no puede emitir directo con el.
        $order = $this->order($store, 'DOCS-ORDER');
        $owner = $this->ownerUser('docs-owner@example.test');
        Sanctum::actingAs($owner, ['*']);
        $this->postJson("/api/v1/orders/{$order->id}/emit-shipping", [
            'delivery_id' => $rider->id,
        ])->assertUnprocessable();
    }

    public function test_unchanged_documents_keep_verification(): void
    {
        [, $store] = $this->signInOwner('docs-keep@example.test');
        $rider = $this->rider($store, email: 'docs-keep-rider@example.test');

        Sanctum::actingAs($rider, ['*']);
        $this->putJson('/api/v1/delivery/profile', [
            'nombre' => 'Igual',
            'apellido_paterno' => 'Repartidor',
            'fecha_nacimiento' => '1990-01-01',
            'foto_id' => $rider->deliveryProfile->fotoID ?? null,
        ])->assertOk();

        $this->assertDatabaseHas('datospersonales', ['idLog' => $rider->id, 'verificado' => '1']);
    }

    /*
    |--------------------------------------------------------------------------
    | P1-2 confirmPayment
    |--------------------------------------------------------------------------
    */

    public function test_confirm_payment_is_idempotent_and_rejects_cancelled_orders(): void
    {
        [, $store] = $this->signInOwner('confirm-owner@example.test');
        $order = $this->order($store, 'CONFIRM-1');
        $this->cartLine($store, $order, null, '2');
        OrderPayment::create([
            'order_id' => $order->id,
            'store_id' => $store->id,
            'method' => 'cash',
            'terms' => 'prepaid',
            'status' => 'pending',
            'currency' => 'MXN',
            'products_amount' => 100,
            'discount_amount' => 0,
            'shipping_amount' => 10,
            'extra_amount' => 0,
            'amount_due' => 110,
            'amount_paid' => 0,
            'amount_refunded' => 0,
        ]);

        $this->withHeader('Idempotency-Key', 'confirm-one')->putJson("/api/v1/orders/{$order->id}/confirm-payment")
            ->assertOk()
            ->assertJsonPath('data.status', '3');
        $this->assertTrue($order->refresh()->isPaid());
        $this->assertDatabaseHas('cart', ['orderC' => $order->order, 'status' => '3']);

        // Repeticion -> 200 idempotente, sin doble mutacion.
        $this->withHeader('Idempotency-Key', 'confirm-one')->putJson("/api/v1/orders/{$order->id}/confirm-payment")
            ->assertOk()
            ->assertJsonPath('message', 'La orden ya estaba pagada.');
        $this->assertDatabaseCount('cart', 1);

        // Cancelada -> 409 sin mutacion.
        $cancelled = $this->order($store, 'CONFIRM-2');
        $this->cartLine($store, $cancelled, null, '2');
        $cancelled->update(['order_state' => PurchaseOrder::STATE_CANCELLED]);
        $this->withHeader('Idempotency-Key', 'confirm-cancelled')->putJson("/api/v1/orders/{$cancelled->id}/confirm-payment")->assertConflict();
        $this->assertDatabaseHas('cart', ['orderC' => $cancelled->order, 'status' => '2']);
    }

    public function test_confirm_payment_rejects_a_legacy_paid_order_without_persisted_method(): void
    {
        [, $store] = $this->signInOwner('confirm-legacy@example.test');
        // Pago llegado por la marca legacy (cart status='3') sin order_state.
        $order = $this->order($store, 'CONFIRM-LEGACY', orderState: PurchaseOrder::STATE_PENDING);
        $this->cartLine($store, $order, null, '3');

        $this->withHeader('Idempotency-Key', 'legacy-confirm')->putJson("/api/v1/orders/{$order->id}/confirm-payment")
            ->assertUnprocessable();

        // La marca legacy no autoriza inferir metodo ni confirmar finanzas.
        $this->assertDatabaseHas('ordencompra', [
            'order' => $order->order,
            'order_state' => PurchaseOrder::STATE_PENDING,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | P1 Cancelar compra -> termina envio
    |--------------------------------------------------------------------------
    */

    public function test_cancelling_the_purchase_marks_shipping_terminal_and_hides_it_everywhere(): void
    {
        [$owner] = $this->signInOwner('cancel-ship@example.test');
        $store = Store::byOwner('cancel-ship@example.test')->firstOrFail();
        $rider = $this->rider($store);

        // Envio pendiente (pool).
        $pending = $this->order($store, 'CANCEL-PENDING');
        $pendingShipping = $this->emitPool($pending);

        // Envio aceptado + activo.
        $active = $this->order($store, 'CANCEL-ACTIVE');
        $activeShipping = $this->emitPool($active);
        Sanctum::actingAs($rider, ['*']);
        $this->postJson("/api/v1/delivery/orders/{$activeShipping}/accept")->assertOk();

        // El dueño cancela ambas compras: los envios pasan a estado terminal '4'.
        Sanctum::actingAs($owner, ['*']);
        $this->withHeader('Idempotency-Key', 'cancel-pending')->postJson("/api/v1/orders/{$pending->id}/cancel")->assertOk();
        $this->withHeader('Idempotency-Key', 'cancel-active')->postJson("/api/v1/orders/{$active->id}/cancel")->assertOk();
        $this->assertDatabaseHas('ordenenvio', ['id' => $pendingShipping, 'status' => ShippingOrder::STATUS_CANCELLED]);
        $this->assertDatabaseHas('ordenenvio', ['id' => $activeShipping, 'status' => ShippingOrder::STATUS_CANCELLED]);

        // Ya no se listan.
        Sanctum::actingAs($rider, ['*']);
        $this->getJson('/api/v1/delivery/active-order')
            ->assertOk()
            ->assertJsonPath('data', null);
        $available = $this->getJson('/api/v1/delivery/available-orders')->assertOk()->json('data');
        $this->assertSame([], $available);

        // Ya no pueden aceptarse, liberarse ni completarse.
        $this->postJson("/api/v1/delivery/orders/{$pendingShipping}/accept")->assertConflict();
        $this->postJson("/api/v1/delivery/orders/{$activeShipping}/cancel")->assertNotFound();
        $this->postJson("/api/v1/delivery/orders/{$activeShipping}/complete")->assertServiceUnavailable();
    }

    public function test_available_orders_excludes_cancelled_orders_even_if_shipping_remains_pending(): void
    {
        [, $store] = $this->signInOwner('cancel-filter@example.test');
        $rider = $this->rider($store);

        $ghost = $this->order($store, 'CANCEL-GHOST');
        $ghostShipping = $this->emitPool($ghost);
        // Estado inconsistente defensivo: envio '0' de una orden ya cancelada.
        $ghost->update(['order_state' => PurchaseOrder::STATE_CANCELLED]);

        $live = $this->order($store, 'CANCEL-LIVE');
        $this->emitPool($live);

        Sanctum::actingAs($rider, ['*']);
        $available = $this->getJson('/api/v1/delivery/available-orders')->assertOk()->json('data');
        $ids = collect($available)->pluck('id')->all();
        $this->assertNotContains($ghostShipping, $ids);
        $this->assertNotEmpty($ids);
    }

    /*
    |--------------------------------------------------------------------------
    | P1 Compatibilidad bundle: assignment_mode inferible
    |--------------------------------------------------------------------------
    */

    public function test_bundle_payload_without_assignment_mode_is_inferred(): void
    {
        [, $store] = $this->signInOwner('bundle-owner@example.test');
        $rider = $this->rider($store);

        // Payload '{}' del bundle legacy -> pool.
        $poolOrder = $this->order($store, 'BUNDLE-POOL');
        $this->postJson("/api/v1/orders/{$poolOrder->id}/emit-shipping", [])
            ->assertCreated()
            ->assertJsonPath('data.assignment_mode', 'pool')
            ->assertJsonPath('data.status', '0');

        // Payload con delivery_id -> direct.
        $directOrder = $this->order($store, 'BUNDLE-DIRECT');
        $this->postJson("/api/v1/orders/{$directOrder->id}/emit-shipping", [
            'delivery_id' => $rider->id,
        ])->assertCreated()
            ->assertJsonPath('data.assignment_mode', 'direct')
            ->assertJsonPath('data.delivery_id', $rider->id);
    }

    /*
    |--------------------------------------------------------------------------
    | P1-4 mail_password oculto
    |--------------------------------------------------------------------------
    */

    public function test_site_settings_never_serialize_mail_password(): void
    {
        $admin = User::create(['name' => 'super@example.test', 'keyvalue' => 'test', 'type' => '1', 'active' => true]);
        $admin->assignRole(Role::findOrCreate('super-admin', 'web'));

        SiteSetting::getSettings()->update([
            'site_name' => 'Ruta 6B',
            'mail_password' => 'secret-smtp-pass',
        ]);
        $id = SiteSetting::getSettings()->id;

        Sanctum::actingAs($admin, ['*']);
        $this->getJson('/api/v1/admin/site-settings')
            ->assertOk()
            ->assertJsonPath('data.site_name', 'Ruta 6B')
            ->assertJsonMissingPath('data.mail_password');
        $raw = $this->get('/api/v1/admin/site-settings')->getContent();
        $this->assertStringNotContainsString('secret-smtp-pass', $raw);

        $this->putJson('/api/v1/admin/site-settings', [
            'site_name' => 'Ruta 6B v2',
            'mail_password' => 'secret-smtp-pass-2',
        ])->assertOk()->assertJsonMissingPath('data.mail_password');
        $rawPut = $this->get('/api/v1/admin/site-settings')->getContent();
        $this->assertStringNotContainsString('secret-smtp-pass-2', $rawPut);
        $this->assertDatabaseHas('site_settings', ['id' => $id, 'mail_password' => 'secret-smtp-pass-2']);
    }

    /*
    |--------------------------------------------------------------------------
    | P2 Doble aceptacion
    |--------------------------------------------------------------------------
    */

    public function test_rider_with_an_active_order_cannot_accept_another(): void
    {
        [, $store] = $this->signInOwner('double-accept@example.test');
        $rider = $this->rider($store);
        $first = $this->order($store, 'DOUBLE-FIRST');
        $second = $this->order($store, 'DOUBLE-SECOND');
        $firstShipping = $this->emitPool($first);
        $secondShipping = $this->emitPool($second);

        Sanctum::actingAs($rider, ['*']);
        $this->postJson("/api/v1/delivery/orders/{$firstShipping}/accept")->assertOk();
        $this->postJson("/api/v1/delivery/orders/{$secondShipping}/accept")->assertConflict();

        $this->assertDatabaseHas('ordenenvio', ['id' => $firstShipping, 'delivery' => $rider->id, 'status' => '1']);
        $this->assertDatabaseHas('ordenenvio', ['id' => $secondShipping, 'delivery' => null, 'status' => '0']);
    }

    public function test_direct_emission_rejects_a_rider_with_an_active_delivery(): void
    {
        [, $store] = $this->signInOwner('direct-active@example.test');
        $rider = $this->rider($store, email: 'direct-active-rider@example.test');

        // El rider ya tiene una entrega activa en otra orden.
        $active = $this->order($store, 'DIRECT-ACTIVE-1');
        $this->directShipping($active, $rider, '1');

        $next = $this->order($store, 'DIRECT-ACTIVE-2');
        $this->cartLine($store, $next, null, '2');

        $this->postJson("/api/v1/orders/{$next->id}/emit-shipping", [
            'delivery_id' => $rider->id,
        ])->assertConflict()
            ->assertJsonPath('message', 'El repartidor ya tiene una entrega activa.');

        // Sin mutaciones: no se creo otro envio y el carrito siguio en '2'.
        $this->assertDatabaseCount('ordenenvio', 1);
        $this->assertDatabaseHas('cart', ['orderC' => $next->order, 'status' => '2']);
    }

    /*
    |--------------------------------------------------------------------------
    | P2 Bloqueo de complecion en config
    |--------------------------------------------------------------------------
    */

    public function test_completion_is_locked_by_config_flag(): void
    {
        $this->assertFalse(config('delivery.completion_enabled'));

        [, $store] = $this->signInOwner('complete-config@example.test');
        $order = $this->order($store, 'COMPLETE-CONFIG');
        $rider = $this->rider($store);
        $shipping = $this->directShipping($order, $rider, '1');

        Sanctum::actingAs($rider, ['*']);
        $this->postJson("/api/v1/delivery/orders/{$shipping}/complete")
            ->assertServiceUnavailable();
        $this->assertDatabaseHas('ordenenvio', ['id' => $shipping, 'status' => '1']);
    }

    /*
    |--------------------------------------------------------------------------
    | P13 SPA + API 404
    |--------------------------------------------------------------------------
    */

    public function test_deep_spa_routes_still_serve_the_bundle(): void
    {
        foreach (['/dashboard/orders', '/delivery/profile', '/catalogo/publico/profundo'] as $uri) {
            $response = $this->get($uri);
            $response->assertOk();
            $this->assertStringContainsString('<!DOCTYPE html>', $response->getContent());
            $this->assertStringNotContainsString('"message"', $response->getContent());
        }
    }

    public function test_unknown_api_routes_return_json_404_for_get_and_post_including_root(): void
    {
        $uris = [
            '/api/v1/not-a-real-endpoint',
            '/api/not-a-real-endpoint',
            '/api',
            '/api/',
        ];

        foreach ($uris as $uri) {
            foreach (['get', 'post'] as $method) {
                $response = $this->{$method}($uri);
                $response->assertNotFound()->assertHeader('content-type', 'application/json');
                $this->assertJson($response->getContent());
                $this->assertStringNotContainsString('<!DOCTYPE html>', $response->getContent());
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function signInOwner(string $email): array
    {
        [$user, $store] = $this->signInStore($email, [
            'delivery.manage', 'delivery.block', 'delivery.complete',
        ]);
        $user->assignRole(Role::findOrCreate('store-owner', 'web'));

        return [$user, $store];
    }

    private function ownerUser(string $email): User
    {
        $user = User::where('name', $email)->firstOrFail();
        $user->assignRole(Role::findOrCreate('store-owner', 'web'));

        return $user;
    }

    private function rider(Store $store, bool $verified = true, string $email = 'fix-rider@example.test'): User
    {
        $user = User::create([
            'name' => $email,
            'keyvalue' => 'test',
            'type' => '3',
            'active' => true,
        ]);
        $user->assignRole(Role::findOrCreate('deliver', 'web'));

        DeliveryProfile::create([
            'idLog' => $user->id,
            'nombre' => 'Repartidor',
            'apellidoPaterno' => 'Seguro',
            'apellidoMaterno' => '6B',
            'verificado' => $verified ? '1' : '0',
        ]);
        DeliveryLink::create([
            'deliveryMan' => $user->id,
            'store' => $store->id,
            'bloqueo' => '0',
        ]);

        return $user;
    }

    private function order(Store $store, string $reference, float $shipping = 0, ?string $orderState = PurchaseOrder::STATE_PENDING): PurchaseOrder
    {
        return PurchaseOrder::create([
            'order' => $reference,
            'serial' => $store->serial,
            'session' => 'fix-cart-'.$reference,
            'tel' => '5551112222',
            'nombre' => 'Cliente',
            'lat' => '19.4326',
            'long' => '-99.1332',
            'date' => now()->format('Y-m-d H:i:s'),
            'total' => 100,
            'totEnvio' => $shipping,
            'order_state' => $orderState,
        ]);
    }

    private function cartLine(Store $store, PurchaseOrder $order, ?int $productId, string $status): Cart
    {
        return Cart::create([
            'product' => $productId,
            'price' => 100,
            'dom' => $store->createdby,
            'user' => $order->session,
            'variation' => $store->serial,
            'cant' => 1,
            'orderC' => $order->order,
            'status' => $status,
        ]);
    }

    private function emitPool(PurchaseOrder $order): int
    {
        return $this->postJson("/api/v1/orders/{$order->id}/emit-shipping", [])
            ->assertCreated()
            ->json('data.id');
    }

    private function directShipping(PurchaseOrder $order, User $rider, string $status): int
    {
        return ShippingOrder::create([
            'tienda' => $order->store->id,
            'delivery' => $rider->id,
            'ordenCompra' => $order->id,
            'fechaIn' => now()->format('Y-m-d H:i:s'),
            'status' => $status,
            'assignment_mode' => 'direct',
        ])->id;
    }

    /**
     * Escribe un PNG minimo real en public/uploads (fixture temporal) para
     * validar la deteccion MIME; no toca ningun archivo existente del bundle.
     */
    private function makeEvidenceImage(): string
    {
        $name = '.tmp-6b-'.bin2hex(random_bytes(4)).'.png';
        $path = public_path('uploads/'.$name);
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));
        $this->evidenceFixture = $path;

        return '/uploads/'.$name;
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

        Schema::create('location', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idDeliver');
            $table->string('latitud');
            $table->string('longitud');
            $table->dateTime('time')->nullable();
        });

        Schema::create('imageevidence', function (Blueprint $table) {
            $table->id();
            $table->string('orderC');
            $table->string('img');
        });

        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('site_name')->nullable();
            $table->string('site_logo')->nullable();
            $table->string('site_favicon')->nullable();
            $table->string('landing_hero_title')->nullable();
            $table->string('landing_hero_text')->nullable();
            $table->json('landing_features')->nullable();
            $table->json('marketplace_colors')->nullable();
            $table->json('login_colors')->nullable();
            $table->json('landing_colors')->nullable();
            $table->text('landing_custom_html')->nullable();
            $table->string('mail_mailer')->nullable();
            $table->string('mail_host')->nullable();
            $table->string('mail_port')->nullable();
            $table->string('mail_username')->nullable();
            $table->string('mail_password')->nullable();
            $table->string('mail_encryption')->nullable();
            $table->string('mail_from_address')->nullable();
            $table->string('mail_from_name')->nullable();
            $table->timestamps();
        });
    }
}
