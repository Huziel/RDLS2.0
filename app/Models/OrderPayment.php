<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderPayment extends Model
{
    protected $fillable = [
        'order_id', 'store_id', 'method', 'terms', 'status', 'currency',
        'products_amount', 'discount_amount', 'shipping_amount', 'extra_amount',
        'amount_due', 'amount_paid', 'amount_refunded', 'frozen_at', 'paid_at',
        'refund_requested_at', 'refunded_at', 'paid_by', 'refunded_by',
        'bank_reference', 'cash_reference', 'refund_reference', 'provider',
        'provider_payment_id',
    ];

    protected $casts = [
        'products_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'shipping_amount' => 'decimal:2',
        'extra_amount' => 'decimal:2',
        'amount_due' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'amount_refunded' => 'decimal:2',
        'frozen_at' => 'datetime',
        'paid_at' => 'datetime',
        'refund_requested_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public const METHODS = ['cash', 'bank_transfer', 'cash_on_delivery', 'mercado_pago', 'legacy_unknown'];

    public const PAID_STATUSES = ['paid', 'refund_pending', 'refunded', 'payment_exception'];

    public function order()
    {
        return $this->belongsTo(PurchaseOrder::class, 'order_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function proofs()
    {
        return $this->hasMany(OrderPaymentProof::class, 'payment_id');
    }

    public function providerTransactions()
    {
        return $this->hasMany(OrderProviderTransaction::class, 'payment_id');
    }

    public function representsCollectedFunds(): bool
    {
        return in_array($this->status, self::PAID_STATUSES, true)
            && (float) $this->amount_paid > 0;
    }
}
