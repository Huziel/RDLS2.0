<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckModuleAccess
{
    public function handle(Request $request, Closure $next, string $module)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        if (! $user->canAccessModule($module)) {
            return response()->json([
                'message' => 'No tienes acceso a este módulo. Debes adquirirlo primero.',
            ], 403);
        }

        return $next($request);
    }
}
