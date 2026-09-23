<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    protected $table = 'ordencompra';

    public $timestamps = false;

    protected $fillable = [
        'order', 'tel', 'serial', 'session', 'lat', 'long', 'total', 'totEnvio', 'nombre', 'date',
        'checkout_key', 'loyalty_discount', 'order_state', 'cancelled_at', 'restock_key', 'delivery_type',
    ];

    protected $casts = [
        'cancelled_at' => 'datetime',
    ];

    public const STATE_PENDING = 'pending';

    public const STATE_PAID = 'paid';

    public const STATE_CANCELLED = 'cancelled';

    public function isPaid(): bool
    {
        $payment = $this->relationLoaded('payment') ? $this->payment : $this->payment()->first();
        if ($payment) {
            return $payment->representsCollectedFunds();
        }

        return (string) $this->order_state === self::STATE_PAID
            || $this->cartItems()->where('status', '3')->exists();
    }

    public function isCancelled(): bool
    {
        return (string) $this->order_state === self::STATE_CANCELLED || $this->cancelled_at !== null;
    }

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

    public function mercadoPagoPayment()
    {
        return $this->hasOne(MercadoPagoPayment::class, 'orderP', 'order');
    }

    public function payment()
    {
        return $this->hasOne(OrderPayment::class, 'order_id');
    }

    public function returnRecord()
    {
        return $this->hasOne(OrderReturn::class, 'order_id');
    }

    public function auditEvents()
    {
        return $this->hasMany(OrderAuditEvent::class, 'order_id');
    }

    public function providerTransactions()
    {
        return $this->hasMany(OrderProviderTransaction::class, 'order_id');
    }
}
