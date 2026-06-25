<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $table = 'cuponera';

    public $timestamps = false;

    protected $fillable = [
        'idTienda', 'nombre', 'tipo', 'codeC', 'uses', 'expired', 'porcent', 'cant', 'valorCompra', 'starts',
    ];

    protected $casts = [
        'expired' => 'date',
        'starts' => 'date',
        'porcent' => 'float',
        'cant' => 'float',
        'valorCompra' => 'float',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'idTienda');
    }

    public function products()
    {
        return $this->hasMany(CouponProduct::class, 'idCupon');
    }

    public function isValid(): bool
    {
        $today = now()->format('Y-m-d');
        return $this->uses > 0
            && $today >= $this->starts
            && $today <= $this->expired;
    }
}
