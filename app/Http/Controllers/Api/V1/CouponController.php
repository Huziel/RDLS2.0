<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CouponController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $store = Store::where('createdby', $user->name)->firstOrFail();

        $coupons = Coupon::with('products')->where('idTienda', $store->id)
            ->orderByDesc('id')->get();

        return response()->json(['data' => $coupons]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $store = Store::where('createdby', $user->name)->firstOrFail();

        $validated = $request->validate([
            'nombre' => ['required', 'string'],
            'tipo' => ['required', 'in:1,2,3'],
            'uses' => ['required', 'integer', 'min:1'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_expiracion' => ['required', 'date'],
            'porcentaje' => ['nullable', 'numeric', 'min:0'],
            'cantidad_descuento' => ['nullable', 'numeric', 'min:0'],
            'valor_compra_minima' => ['nullable', 'numeric', 'min:0'],
            'productos' => ['nullable', 'array'],
            'productos.*.id' => ['integer'],
            'productos.*.porcentaje' => ['numeric', 'min:0', 'max:100'],
        ]);

        $code = strtoupper(Str::random(10));

        $coupon = Coupon::create([
            'idTienda' => $store->id,
            'nombre' => $validated['nombre'],
            'tipo' => $validated['tipo'],
            'codeC' => $code,
            'uses' => $validated['uses'],
            'expired' => $validated['fecha_expiracion'],
            'porcent' => $validated['porcentaje'] ?? 0,
            'cant' => $validated['cantidad_descuento'] ?? 0,
            'valorCompra' => $validated['valor_compra_minima'] ?? 0,
            'starts' => $validated['fecha_inicio'],
        ]);

        if ($validated['tipo'] == '2' && ! empty($validated['productos'])) {
            foreach ($validated['productos'] as $p) {
                $coupon->products()->create([
                    'idData' => $p['id'],
                    'porcent' => $p['porcentaje'],
                ]);
            }
        }

        return response()->json([
            'data' => $coupon->load('products'),
            'message' => "Cupón creado. Código: $code",
        ], 201);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $store = Store::where('createdby', $user->name)->firstOrFail();

        Coupon::where('idTienda', $store->id)->where('id', $id)->delete();

        return response()->json(['message' => 'Cupón eliminado.']);
    }
}
