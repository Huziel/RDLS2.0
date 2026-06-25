<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreTheme extends Model
{
    protected $table = 'temastienda';

    public $timestamps = false;

    protected $fillable = [
        'userId',
        'temaId',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'userId');
    }
}
