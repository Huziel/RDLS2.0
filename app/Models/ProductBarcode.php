<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductBarcode extends Model
{
    protected $table = 'cbarras';

    public $timestamps = false;

    protected $fillable = [
        'idProd',
        'code',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'idProd');
    }
}
