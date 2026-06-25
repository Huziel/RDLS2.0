<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\RegisterUser;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

class GoogleAuthController extends Controller
{
    public function __invoke(Request $request, RegisterUser $action)
    {
        $request->validate([
            'id_token' => ['required', 'string'],
        ]);

        $client = new \Google_Client(['client_id' => config('services.google.client_id')]);
        $payload = $client->verifyIdToken($request->id_token);

        if (! $payload) {
            return response()->json([
                'message' => 'Token de Google inválido.',
            ], 401);
        }

        $email = $payload['email'];
        $name = $payload['name'];
        $userId = $payload['sub'];

        $user = User::where('name', $email)->first();

        if ($user) {
            $abilities = $user->getAllPermissions()->pluck('name')->toArray();
            $token = $user->createToken('auth-token', $abilities);

            return response()->json([
                'data' => [
                    'user' => UserResource::make($user->load('store')),
                    'token' => $token->plainTextToken,
                ],
                'message' => 'Inicio de sesión con Google exitoso.',
            ]);
        }

        $result = $action([
            'email' => $email,
            'password' => $userId,
            'phone' => hexdec(substr(md5(now()->format('YmdHis')), 0, 8)),
            'category' => '0',
            'type' => '1',
        ]);

        return response()->json([
            'data' => [
                'user' => UserResource::make($result['user']),
                'token' => $result['token'],
            ],
            'message' => 'Usuario registrado con Google exitosamente.',
        ], 201);
    }
}
