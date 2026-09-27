<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorePaymentSetting extends Model
{
    protected $fillable = ['store_id', 'cash_on_delivery_enabled'];

    protected $casts = ['cash_on_delivery_enabled' => 'boolean'];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
