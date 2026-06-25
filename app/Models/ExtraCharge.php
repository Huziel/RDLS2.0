<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExtraCharge extends Model
{
    protected $table = 'gastosextras';

    public $timestamps = false;

    protected $fillable = [
        'orderP', 'precio', 'tipoCargo',
    ];

    public function order()
    {
        return $this->belongsTo(PurchaseOrder::class, 'orderP', 'order');
    }
}
