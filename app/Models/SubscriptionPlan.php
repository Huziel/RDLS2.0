<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    protected $fillable = ['name', 'price_percent', 'max_products', 'modules', 'is_default', 'active'];
    protected $casts = ['modules' => 'array', 'price_percent' => 'float', 'is_default' => 'boolean', 'active' => 'boolean'];

    public function subscriptions() { return $this->hasMany(StoreSubscription::class); }

    public function hasModule(string $module): bool
    {
        if (!$this->modules) return true;
        return in_array($module, $this->modules);
    }

    public static function getFreePlan(): ?self
    {
        return static::where('is_default', true)->where('active', true)->first();
    }
}
