<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorePassword extends Model
{
    protected $table = 'passcatalago';

    public $timestamps = false;

    protected $fillable = [
        'idTienda',
        'keyMenu',
    ];

    protected $hidden = [
        'keyMenu',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'idTienda');
    }
}
