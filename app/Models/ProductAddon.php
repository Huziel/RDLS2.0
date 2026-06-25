<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductAddon extends Model
{
    protected $table = 'aditivos';

    public $timestamps = false;

    protected $fillable = [
        'idProd',
        'nombre',
        'precio',
        'categoria',
        'descripcion',
        'activo',
        'stock',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'precio' => 'float',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'idProd');
    }
}
