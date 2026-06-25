<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreExtra extends Model
{
    protected $table = 'masdatosdetienda';

    public $timestamps = false;

    protected $fillable = [
        'idTienda',
        'nombreTienda',
        'horario',
        'banner',
        'texto1',
        'texto2',
        'facebook',
        'instagram',
        'youtube',
        'mercadoLibre',
        'transf1',
        'transf2',
        'nameBanc1',
        'nameBanc2',
        'namePrope1',
        'namePrope2',
        'booking_days',
        'booking_hours',
        'sections',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'idTienda');
    }
}
