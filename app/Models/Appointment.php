<?php namespace App\Models; use Illuminate\Database\Eloquent\Model;
class Appointment extends Model { protected $table='agenda'; public $timestamps=false; protected $fillable=['idLog','nombre','fechaCreacion','feachaApartada','telefono','texto','activo']; }
