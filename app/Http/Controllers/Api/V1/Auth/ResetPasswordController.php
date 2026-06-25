<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\PasswordReset;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class ResetPasswordController extends Controller
{
    public function __invoke(ResetPasswordRequest $request)
    {
        $reset = PasswordReset::where('token', $request->token)
            ->where('fecha', '>', now()->subHour()->format('Y-m-d H:i:s'))
            ->first();

        if (! $reset) {
            return response()->json([
                'message' => 'El codigo de verificacion es invalido o ha expirado.',
            ], 422);
        }

        $user = User::where('name', $request->email)->first();

        if (! $user) {
            return response()->json([
                'message' => 'El usuario no existe.',
            ], 404);
        }

        if ($user->id != $reset->id_Log) {
            return response()->json([
                'message' => 'El codigo no corresponde a este usuario.',
            ], 422);
        }

        $user->update([
            'keyvalue' => Hash::make($request->password),
        ]);

        // Delete used token and any old tokens for this user
        PasswordReset::where('id_Log', $user->id)->delete();

        return response()->json([
            'message' => 'Contrasena actualizada exitosamente.',
        ]);
    }
}
