<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function show(Request $request)
    {
        return response()->json([
            'data' => UserResource::make($request->user()->load('store')),
        ]);
    }

    public function destroy(Request $request)
    {
        $user = $request->user();

        $user->store()->delete();
        $user->tokens()->delete();
        $user->delete();

        return response()->json([
            'message' => 'Cuenta eliminada exitosamente.',
        ]);
    }
}
