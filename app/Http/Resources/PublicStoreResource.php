<?php

namespace App\Http\Resources;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato publico minimo de una tienda (D1 aprobado).
 *
 * Expone unicamente datos comerciales explicitos: NUNCA createdby (email del
 * dueno), phone, adress, lat, long, expires_at ni extras privados. El nombre
 * comercial proviene de masdatosdetienda y se limpia de HTML.
 */
class PublicStoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'serial' => $this->serial,
            'category' => $this->category,
            'logo' => $this->logojpg,
            'logojpg' => $this->logojpg,
            'name' => $this->safeName(),
        ];
    }

    private function safeName(): string
    {
        if (! $this->resource instanceof Store) {
            return 'Tienda';
        }

        $name = trim(strip_tags((string) $this->resource->extra?->nombreTienda));

        return $name === '' ? 'Tienda' : mb_substr($name, 0, 255);
    }
}
