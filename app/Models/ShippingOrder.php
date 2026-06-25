<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingOrder extends Model
{
    protected $table = 'ordenenvio';
    public $timestamps = false;
    protected $fillable = ['tienda', 'delivery', 'ordenCompra', 'fechaIn', 'status'];

    public function store() { return $this->belongsTo(Store::class, 'tienda'); }
    public function deliver() { return $this->belongsTo(User::class, 'delivery'); }
    public function purchaseOrder() { return $this->belongsTo(PurchaseOrder::class, 'ordenCompra', 'id'); }
}
