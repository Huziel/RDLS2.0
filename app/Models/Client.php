<?php namespace App\Models; use Illuminate\Database\Eloquent\Model;
class Client extends Model { protected $fillable=['store_id','name','email','phone','tags','notes','stage','last_purchase_at','total_spent','purchase_count'];
protected $casts=['tags'=>'array','last_purchase_at'=>'datetime','total_spent'=>'float'];
public function store(){return $this->belongsTo(Store::class,'store_id');}
public static function findByPhoneOrEmail($storeId,$phone,$email,$name){
    return static::where('store_id',$storeId)->where(function($q)use($phone,$email){
        if ($phone) $q->where('phone',$phone);
        if ($email) $q->orWhere('email',$email);
    })->first();
}
}
