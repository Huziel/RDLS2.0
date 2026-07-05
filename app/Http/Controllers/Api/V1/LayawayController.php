<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Layaway;
use App\Models\Store;
use Illuminate\Http\Request;

class LayawayController extends Controller
{
    public function index(Request $request)
    {
        $store = Store::byOwner($request->user()->name)->firstOrFail();
        $layaways = Layaway::where('store_id', $store->id)->orderByDesc('id')->paginate(20);
        return response()->json($layaways);
    }

    public function store(Request $request)
    {
        $store = Store::byOwner($request->user()->name)->firstOrFail();
        $request->validate([
            'client_name' => 'required|string',
            'client_phone' => 'nullable|string',
            'total' => 'required|numeric|min:0',
            'paid' => 'required|numeric|min:0',
            'products' => 'nullable|array',
            'notes' => 'nullable|string',
        ]);
        $data = $request->only(['client_name', 'client_phone', 'total', 'paid', 'products', 'notes']);
        $data['store_id'] = $store->id;
        $data['pending'] = $data['total'] - $data['paid'];
        $data['status'] = $data['pending'] <= 0 ? 'completed' : 'active';
        $layaway = Layaway::create($data);
        return response()->json(['data' => $layaway, 'message' => 'Apartado creado.'], 201);
    }

    public function update(Request $request, $id)
    {
        $store = Store::byOwner($request->user()->name)->firstOrFail();
        $layaway = Layaway::where('store_id', $store->id)->findOrFail($id);
        $request->validate(['paid' => 'required|numeric|min:0']);
        $layaway->paid = $request->paid;
        $layaway->pending = max(0, $layaway->total - $layaway->paid);
        $layaway->status = $layaway->pending <= 0 ? 'completed' : 'active';
        $layaway->save();
        return response()->json(['data' => $layaway, 'message' => 'Pago actualizado.']);
    }

    public function destroy(Request $request, $id)
    {
        $store = Store::byOwner($request->user()->name)->firstOrFail();
        Layaway::where('store_id', $store->id)->where('id', $id)->delete();
        return response()->json(['message' => 'Apartado eliminado.']);
    }
}
