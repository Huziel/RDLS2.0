<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RegisterUser
{
    public function __invoke(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $hash = Hash::make($data['password']);

            $user = User::create([
                'name' => $data['email'],
                'keyvalue' => $hash,
                'type' => $data['type'],
            ]);

            $expiration = now()->addYear()->format('d-m-Y H:i:s');
            $serial = session()->getId() . rand(1, 999);

            $store = Store::create([
                'serial' => $serial,
                'time' => $expiration,
                'phone' => $data['phone'],
                'session' => null,
                'createdby' => $data['email'],
                'type' => '1',
                'category' => $data['category'] ?? '0',
                'color' => '10|4|10|1',
                'adress' => null,
                'logo' => null,
                'locales' => null,
                'logojpg' => null,
                'paypal' => null,
                'lat' => null,
                'long' => null,
            ]);

            $roleName = match ($data['type']) {
                '3' => 'deliver',
                '4' => 'customer',
                default => 'store-owner',
            };

            $user->assignRole($roleName);

            $token = $user->createToken('auth-token', $user->getAllPermissions()->pluck('name')->toArray());

            return [
                'user' => $user->load('store'),
                'token' => $token->plainTextToken,
                'store' => $store,
            ];
        });
    }
}
