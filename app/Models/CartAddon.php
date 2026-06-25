<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CartAddon extends Model
{
    protected $table = 'cartaditivos';

    public $timestamps = false;

    protected $fillable = [
        'noOrder', 'idAditivo', 'session',
    ];

    public function cart()
    {
        return $this->belongsTo(Cart::class, 'noOrder');
    }

    public function addon()
    {
        return $this->belongsTo(ProductAddon::class, 'idAditivo');
    }
}
