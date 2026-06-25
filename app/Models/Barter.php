<?php namespace App\Models; use Illuminate\Database\Eloquent\Model;
class Barter extends Model { protected $table='trueques'; public $timestamps=false; protected $fillable=['idPedido','idClient','fecha'];
public function products(){return $this->hasMany(BarterProduct::class,'idTrueque');} }
