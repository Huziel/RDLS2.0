<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreSubscription extends Model
{
    protected $fillable = ['store_id', 'subscription_plan_id', 'monthly_sales', 'amount_due', 'status', 'starts_at', 'ends_at', 'last_payment_at'];
    protected $casts = ['monthly_sales' => 'float', 'amount_due' => 'float', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'last_payment_at' => 'datetime'];

    public function plan() { return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id'); }
    public function store() { return $this->belongsTo(Store::class, 'store_id'); }
    public function payments() { return $this->hasMany(SubscriptionPayment::class, 'store_subscription_id'); }

    public function hasModule(string $module): bool { return $this->plan->hasModule($module); }

    public static function getActive(int $storeId): ?self
    {
        return static::where('store_id', $storeId)->where('status', 'active')->with('plan')->first();
    }

    public static function getOrCreateDefault(int $storeId): self
    {
        $existing = static::getActive($storeId);
        if ($existing) return $existing;
        $plan = SubscriptionPlan::getFreePlan();
        return static::create(['store_id' => $storeId, 'subscription_plan_id' => $plan ? $plan->id : 1, 'status' => 'active', 'starts_at' => now()]);
    }

    public function addSale(float $amount): void
    {
        $this->increment('monthly_sales', $amount);
        $this->amount_due = $this->monthly_sales * ($this->plan->price_percent / 100);
        $this->save();
    }
}
