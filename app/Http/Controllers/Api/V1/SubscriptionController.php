<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreSubscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPayment;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    // Admin: list plans
    public function indexPlans(Request $request)
    {
        if (!$request->user()->hasRole('super-admin')) abort(403);
        return response()->json(['data' => SubscriptionPlan::orderBy('price_percent')->get()]);
    }

    // Admin: create/update plan
    public function storePlan(Request $request)
    {
        if (!$request->user()->hasRole('super-admin')) abort(403);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'price_percent' => 'required|numeric|min:0|max:100',
            'max_products' => 'nullable|integer|min:0',
            'modules' => 'nullable|array',
            'is_default' => 'boolean',
            'active' => 'boolean',
        ]);
        $plan = SubscriptionPlan::create($validated);
        if ($plan->is_default) SubscriptionPlan::where('id', '!=', $plan->id)->update(['is_default' => false]);
        return response()->json(['data' => $plan, 'message' => 'Plan creado.'], 201);
    }

    public function updatePlan(Request $request, $id)
    {
        if (!$request->user()->hasRole('super-admin')) abort(403);
        $plan = SubscriptionPlan::findOrFail($id);
        $plan->update($request->only(['name', 'price_percent', 'max_products', 'modules', 'is_default', 'active']));
        if ($plan->is_default) SubscriptionPlan::where('id', '!=', $plan->id)->update(['is_default' => false]);
        return response()->json(['data' => $plan, 'message' => 'Plan actualizado.']);
    }

    public function deletePlan(Request $request, $id)
    {
        if (!$request->user()->hasRole('super-admin')) abort(403);
        SubscriptionPlan::findOrFail($id)->delete();
        return response()->json(['message' => 'Plan eliminado.']);
    }

    // Admin: list store subscriptions
    public function indexStoreSubscriptions(Request $request)
    {
        if (!$request->user()->hasRole('super-admin')) abort(403);
        $subs = StoreSubscription::with(['plan', 'store.extra'])->orderByDesc('id')->paginate(20);
        $data = $subs->through(fn($s) => [
            'id' => $s->id, 'store_id' => $s->store_id,
            'store_serial' => $s->store->serial ?? '', 'store_name' => $s->store->extra->nombreTienda ?? $s->store->serial ?? '',
            'plan_name' => $s->plan->name ?? 'Sin plan', 'price_percent' => $s->plan->price_percent ?? 0,
            'monthly_sales' => $s->monthly_sales, 'amount_due' => $s->amount_due,
            'status' => $s->status, 'starts_at' => $s->starts_at,
        ]);
        return response()->json(['data' => $data->items(), 'meta' => ['current_page' => $subs->currentPage(), 'last_page' => $subs->lastPage(), 'total' => $subs->total()]]);
    }

    // Admin: auto-create subscriptions for all existing stores
    public function createAllSubscriptions(Request $request)
    {
        if (!$request->user()->hasRole('super-admin')) abort(403);
        $plan = SubscriptionPlan::getFreePlan();
        if (!$plan) return response()->json(['message' => 'No hay plan gratuito configurado.'], 422);

        $storeIds = \App\Models\Store::pluck('id');
        $count = 0;
        foreach ($storeIds as $storeId) {
            $existing = StoreSubscription::where('store_id', $storeId)->where('status', 'active')->first();
            if (!$existing) {
                StoreSubscription::create(['store_id' => $storeId, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()]);
                $count++;
            }
        }
        return response()->json(['message' => "$count suscripciones creadas para tiendas existentes."]);
    }

    // Admin: assign subscription to store
    public function assignSubscription(Request $request)
    {
        if (!$request->user()->hasRole('super-admin')) abort(403);
        $request->validate(['store_id' => 'required|integer', 'plan_id' => 'required|integer']);
        $plan = SubscriptionPlan::findOrFail($request->plan_id);
        $existing = StoreSubscription::where('store_id', $request->store_id)->where('status', 'active')->first();
        if ($existing) $existing->update(['status' => 'cancelled']);
        $sub = StoreSubscription::create(['store_id' => $request->store_id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()]);
        return response()->json(['data' => $sub, 'message' => 'Suscripcion asignada.']);
    }

    // Store owner: get my subscription
    public function mySubscription(Request $request)
    {
        $store = Store::byOwner($request->user()->name)->firstOrFail();
        $sub = StoreSubscription::getOrCreateDefault($store->id);
        return response()->json(['data' => [
            'plan_name' => $sub->plan->name, 'price_percent' => $sub->plan->price_percent,
            'max_products' => $sub->plan->max_products,
            'modules' => $sub->plan->modules,
            'monthly_sales' => $sub->monthly_sales, 'amount_due' => $sub->amount_due,
            'status' => $sub->status,
            'is_free' => $sub->plan->is_default && $sub->plan->price_percent == 0,
        ]]);
    }

    // Public/Internal: check if subscription has module access
    public function checkModule(Request $request)
    {
        $store = Store::byOwner($request->user()->name)->firstOrFail();
        $sub = StoreSubscription::getActive($store->id);
        $module = $request->input('module');
        if (!$sub) return response()->json(['access' => false, 'message' => 'Sin suscripcion activa']);
        $hasAccess = $sub->hasModule($module);
        return response()->json(['access' => $hasAccess, 'module' => $module]);
    }
}
