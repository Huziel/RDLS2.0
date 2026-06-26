<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyConfig extends Model
{
    protected $fillable = ['store_id', 'points_per_peso', 'pesos_per_point', 'minimum_points_to_redeem', 'enabled'];
    protected $casts = ['enabled' => 'boolean'];

    public function store() { return $this->belongsTo(Store::class, 'store_id'); }

    public static function getConfig($storeId): self
    {
        return static::firstOrCreate(['store_id' => $storeId], [
            'points_per_peso' => 1, 'pesos_per_point' => 1, 'minimum_points_to_redeem' => 100, 'enabled' => true,
        ]);
    }
}
