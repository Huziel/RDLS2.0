<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductAddonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'precio' => (float) $this->precio,
            'categoria' => $this->categoria,
            'descripcion' => $this->descripcion,
            'activo' => (bool) $this->activo,
            'stock' => $this->stock,
        ];
    }
}
