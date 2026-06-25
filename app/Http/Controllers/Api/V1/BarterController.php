<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Barter;
use App\Models\BarterProduct;
use Illuminate\Http\Request;

class BarterController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate(['id_pedido' => 'required|integer', 'productos' => 'required|array', 'productos.*.id' => 'integer', 'productos.*.cantidad' => 'integer|min:1']);
        $user = $request->user();

        $exists = Barter::where('idPedido', $validated['id_pedido'])->where('idClient', $user->id)->first();
        if ($exists) return response()->json(['message' => 'Ya existe un trueque para este pedido.'], 422);

        $barter = Barter::create(['idPedido' => $validated['id_pedido'], 'idClient' => $user->id, 'fecha' => now()->format('Y-m-d H:i:s')]);
        foreach ($validated['productos'] as $p) {
            BarterProduct::create(['idTrueque' => $barter->id, 'idProduct' => $p['id'], 'cantidad' => $p['cantidad']]);
        }
        return response()->json(['data' => $barter->load('products'), 'message' => 'Trueque creado.'], 201);
    }

    public function show($id)
    {
        $barter = Barter::with('products')->findOrFail($id);
        $barter->products->load('product:id,keyy,number,link');
        return response()->json(['data' => $barter]);
    }
}
