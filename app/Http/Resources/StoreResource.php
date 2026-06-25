<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'serial' => $this->serial,
            'phone' => $this->phone,
            'createdby' => $this->createdby,
            'category' => $this->category,
            'adress' => $this->adress,
            'logo' => $this->logojpg,
            'logojpg' => $this->logojpg,
            'lat' => $this->lat,
            'long' => $this->long,
            'expires_at' => $this->time,
        ];
    }
}
