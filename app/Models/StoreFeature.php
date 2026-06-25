<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreFeature extends Model
{
    protected $table = 'caracteristicasadicionales';

    public $timestamps = false;

    protected $fillable = [
        'idTienda',
        'idComponent',
        'active',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'idTienda');
    }
}
