<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\RegisterUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;

class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request, RegisterUser $action)
    {
        $existingUser = \App\Models\User::where('name', $request->email)->first();
        $existingPhone = \App\Models\Store::where('phone', $request->phone)->first();

        if ($existingUser || $existingPhone) {
            return response()->json([
                'message' => 'Ya existe un usuario registrado con el mismo correo o número telefónico.',
            ], 422);
        }

        $result = $action([
            'email' => $request->email,
            'password' => $request->password,
            'phone' => $request->phone,
            'category' => $request->category ?? '0',
            'type' => $request->type,
        ]);

        return response()->json([
            'data' => [
                'user' => UserResource::make($result['user']),
                'token' => $result['token'],
            ],
            'message' => 'Usuario registrado exitosamente.',
        ], 201);
    }
}
