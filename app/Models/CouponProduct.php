<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CouponProduct extends Model
{
    protected $table = 'productcupon';

    public $timestamps = false;

    protected $fillable = [
        'idCupon', 'idData', 'porcent',
    ];
}
