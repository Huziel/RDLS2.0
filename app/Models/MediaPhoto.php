<?php namespace App\Models; use Illuminate\Database\Eloquent\Model;
class MediaPhoto extends Model { protected $table='fotos'; public $timestamps=false; protected $fillable=['idLog','urlFoto','fecha']; }
