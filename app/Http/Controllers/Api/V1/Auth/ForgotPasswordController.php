<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Models\PasswordReset;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Support\Str;

class ForgotPasswordController extends Controller
{
    public function __invoke(ForgotPasswordRequest $request)
    {
        $user = User::where('name', $request->email)->first();

        if (! $user) {
            return response()->json([
                'message' => 'Si el correo existe, recibira un codigo de recuperacion.',
            ]);
        }

        // Rate limit: max 3 requests per 15 min per user
        $recent = PasswordReset::where('id_Log', $user->id)
            ->where('fecha', '>', now()->subMinutes(15)->format('Y-m-d H:i:s'))
            ->count();
        if ($recent >= 3) {
            return response()->json([
                'message' => 'Demasiados intentos. Intente de nuevo en 15 minutos.',
            ], 429);
        }

        // Generate 6-digit numeric code
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        PasswordReset::updateOrCreate(
            ['id_Log' => $user->id],
            [
                'token' => $code,
                'fecha' => now()->format('Y-m-d H:i:s'),
            ]
        );

        // Send email with the code
        $sent = MailService::send(
            $user->name,
            'Recuperacion de contrasena',
            $this->buildEmailBody($code)
        );

        return response()->json([
            'message' => 'Se ha enviado un codigo de recuperacion a tu correo.',
        ]);
    }

    private function buildEmailBody(string $code): string
    {
        return '
        <div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;padding:24px">
            <h2 style="color:#333">Recuperacion de contrasena</h2>
            <p>Has solicitado restablecer tu contrasena. Usa el siguiente codigo:</p>
            <div style="background:#f4f4f4;padding:20px;text-align:center;border-radius:8px;margin:16px 0">
                <span style="font-size:32px;font-weight:700;letter-spacing:6px;color:#333">'.$code.'</span>
            </div>
            <p style="color:#666;font-size:14px">Este codigo expira en 1 hora. Si no solicitaste este cambio, ignora este mensaje.</p>
        </div>';
    }
}
