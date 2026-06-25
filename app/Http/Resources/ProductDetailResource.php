<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->keyy,
            'precio' => $this->number,
            'imagen' => $this->link,
            'descripcion' => $this->dscr,
            'variable' => $this->var,
            'categoria' => $this->category,
            'activo' => (bool) $this->active,
            'store_session' => $this->session,
            'stock' => [
                'cantidad' => $this->stock?->stock ?? 0,
                'type' => $this->stock?->typesd,
            ],
            'codigo_barras' => $this->barcode?->code,
            'imagenes' => ProductImageResource::collection($this->whenLoaded('images')),
            'aditivos' => ProductAddonResource::collection($this->whenLoaded('addons')),
        ];
    }
}
