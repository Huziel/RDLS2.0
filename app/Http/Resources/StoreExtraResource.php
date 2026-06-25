<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoreExtraResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre_tienda' => $this->nombreTienda,
            'horario' => $this->horario,
            'banner' => $this->banner,
            'texto1' => $this->texto1,
            'texto2' => $this->texto2,
            'facebook' => $this->facebook,
            'instagram' => $this->instagram,
            'youtube' => $this->youtube,
            'mercado_libre' => $this->mercadoLibre,
            'transferencia1' => $this->transf1,
            'transferencia2' => $this->transf2,
            'nombre_banco1' => $this->nameBanc1,
            'nombre_banco2' => $this->nameBanc2,
            'nombre_propietario1' => $this->namePrope1,
            'nombre_propietario2' => $this->namePrope2,
            'booking_days' => $this->booking_days,
            'booking_hours' => $this->booking_hours,
            'sections' => $this->sections,
        ];
    }
}
