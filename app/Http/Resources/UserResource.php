<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'type_label' => $this->type ? \App\Enums\UserType::from($this->type)->label() : null,
            'roles' => $this->whenLoaded('roles', fn () => $this->getRoleNames()),
            'permissions' => $this->getAllPermissions()->pluck('name'),
            'store' => $this->whenLoaded('store', fn () => StoreResource::make($this->store)),
        ];
    }
}
