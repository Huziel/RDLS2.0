<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingForm extends Model
{
    protected $table = 'formularioenvios';

    public $timestamps = false;

    protected $fillable = [
        'noOrder', 'nombre', 'direccion', 'ciudad', 'pais', 'codigoPostal', 'tipoEnvio',
    ];

    public function order()
    {
        return $this->belongsTo(PurchaseOrder::class, 'noOrder', 'order');
    }
}
