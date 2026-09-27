<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicOrderTicket extends JsonResource
{
    public function toArray(Request $request): array
    {
        $projection = PublicOrderProjection::make($this->resource)->resolve($request);

        return $projection + [
            'cliente' => $this->nombre,
            'fecha' => $this->date,
            'total' => (float) $this->total,
            'envio' => (float) $this->totEnvio,
            'items' => $this->cartItems->map(fn ($item) => [
                'name' => $item->productData?->keyy ?? 'Producto #'.$item->product,
                'image' => $item->productData?->link,
                'qty' => (int) $item->cant,
                'price' => (float) $item->price,
                'addons' => $item->addons->map(fn ($cartAddon) => [
                    'name' => $cartAddon->addon?->nombre ?? '',
                    'price' => (float) ($cartAddon->addon?->precio ?? 0),
                ])->values(),
            ])->values(),
        ];
    }
}
