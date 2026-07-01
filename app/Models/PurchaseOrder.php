<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    protected $table = 'ordencompra';

    public $timestamps = false;

    protected $fillable = [
        'order', 'tel', 'serial', 'session', 'lat', 'long', 'total', 'totEnvio', 'nombre', 'date',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'serial', 'serial');
    }

    public function cartItems()
    {
        return $this->hasMany(Cart::class, 'orderC', 'order');
    }

    public function shippingForm()
    {
        return $this->hasOne(ShippingForm::class, 'noOrder', 'order');
    }

    public function extraCharges()
    {
        return $this->hasMany(ExtraCharge::class, 'orderP', 'order');
    }

    public function shippingOrder()
    {
        return $this->hasOne(ShippingOrder::class, 'ordenCompra', 'id');
    }
}
