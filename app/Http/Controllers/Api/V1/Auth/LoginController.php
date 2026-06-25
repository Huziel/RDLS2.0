<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    public function __invoke(LoginRequest $request)
    {
        $email = str_replace(' ', '', $request->email);

        $user = User::where('name', $email)->first();

        if (! $user) {
            $store = Store::where('phone', $email)->first();
            if ($store) {
                $user = User::where('name', $store->createdby)->first();
            }
        }

        if (! $user || ! Hash::check($request->password, $user->keyvalue)) {
            return response()->json([
                'message' => 'Credenciales incorrectas.',
            ], 401);
        }

        $abilities = $user->getAllPermissions()->pluck('name')->toArray();
        $token = $user->createToken('auth-token', $abilities);

        if ($request->boolean('remember')) {
            $token->accessToken->expires_at = now()->addDays(30);
            $token->accessToken->save();
        }

        return response()->json([
            'data' => [
                'user' => UserResource::make($user->load('store')),
                'token' => $token->plainTextToken,
            ],
            'message' => 'Inicio de sesión exitoso.',
        ]);
    }
}
