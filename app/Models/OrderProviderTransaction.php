<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderProviderTransaction extends Model
{
    protected $fillable = [
        'payment_id', 'store_id', 'order_id', 'provider', 'provider_payment_id',
        'preference_id', 'amount', 'currency', 'remote_status',
    ];

    protected $casts = ['amount' => 'decimal:2'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Provider transactions are append-only.'));
        static::deleting(fn () => throw new \LogicException('Provider transactions are append-only.'));
    }

    public function payment()
    {
        return $this->belongsTo(OrderPayment::class, 'payment_id');
    }

    public function order()
    {
        return $this->belongsTo(PurchaseOrder::class, 'order_id');
    }
}
