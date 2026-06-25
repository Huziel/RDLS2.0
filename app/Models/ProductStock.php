<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductStock extends Model
{
    protected $table = 'stock';

    public $timestamps = false;

    protected $fillable = [
        'idProd',
        'stock',
        'typesd',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'idProd');
    }
}
