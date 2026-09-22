<?php

namespace Tests\Feature;

use App\Models\StoreSubscription;
use App\Models\SubscriptionPlan;
use Spatie\Permission\Models\Role;
use Tests\Support\InteractsWithSalesSchema;
use Tests\TestCase;

class ProductPlanLimitTest extends TestCase
{
    use InteractsWithSalesSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesSchema();
    }

    private function makePlan(string $name, ?int $maxProducts, bool $isDefault = false): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'name' => $name,
            'price_percent' => 0,
            'max_products' => $maxProducts,
            'modules' => ['products'],
            'is_default' => $isDefault,
            'active' => true,
        ]);
    }

    private function subscribe(int $storeId, int $planId): StoreSubscription
    {
        return StoreSubscription::create([
            'store_id' => $storeId,
            'subscription_plan_id' => $planId,
            'status' => 'active',
            'starts_at' => now(),
        ]);
    }

    private function payload(string $name = 'Producto nuevo'): array
    {
        return ['nombre' => $name, 'precio' => 100];
    }

    public function test_store_with_gratuito_plan_at_limit_rejects_new_product(): void
    {
        [$user, $store] = $this->signInStore('owner@example.test', ['products.create']);
        $plan = $this->makePlan('Gratuito', 10, true);
        $this->subscribe($store->id, $plan->id);

        foreach (range(1, 10) as $i) {
            $this->createProduct('owner@example.test', ['keyy' => "Producto {$i}", 'number' => (string) $i]);
        }

        $this->postJson('/api/v1/products', $this->payload())
            ->assertStatus(422)
            ->assertJson(['message' => 'Alcanzaste el limite de productos de tu plan.']);
    }

    public function test_super_admin_bypasses_the_product_plan_limit(): void
    {
        [$user, $store] = $this->signInStore('super@example.test', ['products.create']);
        Role::findOrCreate('super-admin', 'web');
        $user->assignRole('super-admin');
        $plan = $this->makePlan('Gratuito', 10, true);
        $this->subscribe($store->id, $plan->id);

        foreach (range(1, 10) as $i) {
            $this->createProduct('super@example.test', ['keyy' => "Producto {$i}", 'number' => (string) $i]);
        }

        $this->postJson('/api/v1/products', $this->payload())
            ->assertStatus(201)
            ->assertJson(['message' => 'Producto creado exitosamente.']);
    }

    public function test_owner_under_the_limit_can_create_a_product(): void
    {
        [$user, $store] = $this->signInStore('under@example.test', ['products.create']);
        $plan = $this->makePlan('Gratuito', 10, true);
        $this->subscribe($store->id, $plan->id);

        foreach (range(1, 9) as $i) {
            $this->createProduct('under@example.test', ['keyy' => "Producto {$i}", 'number' => (string) $i]);
        }

        $this->postJson('/api/v1/products', $this->payload())
            ->assertStatus(201)
            ->assertJson(['message' => 'Producto creado exitosamente.']);
    }
}
