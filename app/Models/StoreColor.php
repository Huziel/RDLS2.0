<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreColor extends Model
{
    protected $table = 'colores';

    public $timestamps = false;

    protected $fillable = [
        'idStore',
        'coloruno',
        'colordos',
        'colortres',
        'colorcuatro',
        'colorcinco',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'idStore');
    }
}
