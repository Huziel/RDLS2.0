<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApartadoConfig extends Model
{
    protected $table = 'apartados';

    public $timestamps = false;

    protected $fillable = [
        'tienda',
        'value',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'tienda');
    }
}
