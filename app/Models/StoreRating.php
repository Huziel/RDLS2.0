<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreRating extends Model
{
    protected $table = 'calificaciontienda';

    public $timestamps = false;

    protected $fillable = [
        'idTieda',
        'idUser',
        'calificacion',
        'comentario',
        'fotoComentario',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'idTieda');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'idUser');
    }
}
