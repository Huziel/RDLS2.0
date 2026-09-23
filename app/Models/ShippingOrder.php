<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingOrder extends Model
{
    protected $table = 'ordenenvio';

    public $timestamps = false;

    protected $fillable = ['tienda', 'delivery', 'ordenCompra', 'fechaIn', 'status', 'assignment_mode'];

    /**
     * Estados de ordenenvio verificados contra el codigo existente:
     * '0' pendiente, '1' en camino, '2' en proceso, '3' entregado
     * (usado tambien por deliveryHistory como completado).
     * '4' es el estado terminal CANCELADO introducido por FASE 6B:
     * no colisiona con '3' (entregado) ni con los mapas status_label
     * de OrderController una vez anadida la etiqueta 'Cancelado'.
     */
    public const STATUS_CANCELLED = '4';

    public function store()
    {
        return $this->belongsTo(Store::class, 'tienda');
    }

    public function deliver()
    {
        return $this->belongsTo(User::class, 'delivery');
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'ordenCompra', 'id');
    }
}
