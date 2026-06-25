<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
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
            'stock' => $this->whenLoaded('stock', fn () => $this->stock?->stock ?? 0),
            'codigo_barras' => $this->whenLoaded('barcode', fn () => $this->barcode?->code),
            'imagenes' => $this->whenLoaded('images', fn () => $this->images->pluck('picture')),
        ];
    }
}
