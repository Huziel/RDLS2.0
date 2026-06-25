<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImage extends Model
{
    protected $table = 'img';

    public $timestamps = false;

    protected $fillable = [
        'picture',
        'dom',
        'product',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product');
    }
}
