<?php namespace App\Models; use Illuminate\Database\Eloquent\Model;
class BarterProduct extends Model { protected $table='truequesproducto'; public $timestamps=false; protected $fillable=['idTrueque','idProduct','cantidad']; }
