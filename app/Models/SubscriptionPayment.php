<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPayment extends Model
{
    protected $fillable = ['store_id', 'store_subscription_id', 'monthly_sales', 'percent', 'amount', 'period', 'status', 'paid_at'];
    protected $casts = ['monthly_sales' => 'float', 'percent' => 'float', 'amount' => 'float', 'paid_at' => 'datetime'];
}
