<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderReturn extends Model
{
    protected $fillable = [
        'order_id', 'store_id', 'status', 'received_by', 'received_at', 'restock_key',
    ];

    protected $casts = ['received_at' => 'datetime'];

    public function order()
    {
        return $this->belongsTo(PurchaseOrder::class, 'order_id');
    }
}
