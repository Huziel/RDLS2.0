<?php namespace App\Models; use Illuminate\Database\Eloquent\Model;
class MediaVideo extends Model { protected $table='videos'; public $timestamps=false; protected $fillable=['idLog','urlVideo','fecha']; }
