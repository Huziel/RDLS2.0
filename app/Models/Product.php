<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $table = 'data';

    public $timestamps = false;

    protected $fillable = [
        'number',
        'keyy',
        'link',
        'session',
        'dscr',
        'var',
        'category',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'session', 'createdby');
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class, 'product');
    }

    public function stock()
    {
        return $this->hasOne(ProductStock::class, 'idProd');
    }

    public function barcode()
    {
        return $this->hasOne(ProductBarcode::class, 'idProd');
    }

    public function addons()
    {
        return $this->hasMany(ProductAddon::class, 'idProd');
    }

    public function scopeActive($query)
    {
        return $query->where('active', 1);
    }

    public function scopeByStore($query, $storeCreatedBy)
    {
        return $query->where('session', $storeCreatedBy);
    }
}
