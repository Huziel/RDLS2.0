<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryProfile extends Model
{
    protected $table = 'datospersonales';
    public $timestamps = false;
    protected $fillable = [
        'nombre', 'apellidoPaterno', 'apellidoMaterno', 'fechaNacimiento',
        'placas', 'tipo', 'modelo', 'color', 'fotoPorfile', 'fotoID',
        'fotoDomicilio', 'idLog', 'verificado',
    ];
    public function user() { return $this->belongsTo(User::class, 'idLog'); }
    public function photo() { return $this->hasOne(DeliveryPhoto::class, 'idUser', 'idLog'); }
}
