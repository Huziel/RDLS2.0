<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderPaymentProof extends Model
{
    protected $fillable = [
        'payment_id', 'store_id', 'disk', 'path', 'mime', 'size', 'sha256', 'reference',
    ];

    protected $hidden = ['disk', 'path', 'sha256'];

    public function payment()
    {
        return $this->belongsTo(OrderPayment::class, 'payment_id');
    }
}
