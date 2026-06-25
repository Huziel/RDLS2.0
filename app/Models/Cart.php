<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    protected $table = 'cart';

    public $timestamps = false;

    protected $fillable = [
        'product', 'price', 'dom', 'user', 'variation', 'cant', 'orderC', 'status',
    ];

    public function productData()
    {
        return $this->belongsTo(Product::class, 'product');
    }

    public function addons()
    {
        return $this->hasMany(CartAddon::class, 'noOrder');
    }

    public function scopeActive($query)
    {
        return $query->where('status', '0');
    }

    public function scopeByUser($query, $userId)
    {
        return $query->where('user', $userId);
    }

    public function scopeByStore($query, $storeSerial)
    {
        return $query->where('variation', $storeSerial);
    }
}
