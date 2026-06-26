<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\PurchaseOrder;
use App\Models\Store;
use Illuminate\Http\Request;

class CrmController extends Controller
{
    public function index(Request $request)
    {
        $store = Store::byOwner($request->user()->name)->firstOrFail();
        $query = Client::where('store_id', $store->id);

        if ($request->has('search')) {
            $q = '%' . $request->search . '%';
            $query->where(function ($q2) use ($q) { $q2->where('name', 'like', $q)->orWhere('phone', 'like', $q)->orWhere('email', 'like', $q); });
        }
        if ($request->has('stage') && $request->stage !== 'all') $query->where('stage', $request->stage);
        if ($request->has('tag') && $request->tag !== 'all') $query->whereJsonContains('tags', $request->tag);

        $clients = $query->orderByDesc('updated_at')->paginate($request->get('per_page', 20));

        return response()->json($clients);
    }

    public function show($id)
    {
        $store = Store::byOwner(request()->user()->name)->firstOrFail();
        $client = Client::where('store_id', $store->id)->findOrFail($id);

        $orders = PurchaseOrder::where('nombre', 'like', '%' . $client->name . '%')
            ->orWhere('tel', $client->phone)
            ->orderByDesc('id')->limit(20)->get(['id', 'order', 'total', 'totEnvio', 'date']);

        return response()->json(['data' => ['client' => $client, 'orders' => $orders]]);
    }

    public function store(Request $request)
    {
        $store = Store::byOwner($request->user()->name)->firstOrFail();
        $validated = $request->validate([
            'name' => 'required|string', 'email' => 'nullable|email', 'phone' => 'nullable|string',
            'tags' => 'nullable|array', 'notes' => 'nullable|string', 'stage' => 'nullable|string',
        ]);
        $validated['store_id'] = $store->id;
        $client = Client::create($validated);
        return response()->json(['data' => $client, 'message' => 'Cliente creado.'], 201);
    }

    public function update(Request $request, $id)
    {
        $store = Store::byOwner($request->user()->name)->firstOrFail();
        $client = Client::where('store_id', $store->id)->findOrFail($id);
        $client->update($request->only(['name', 'email', 'phone', 'tags', 'notes', 'stage']));
        return response()->json(['data' => $client, 'message' => 'Cliente actualizado.']);
    }

    public function destroy($id)
    {
        $store = Store::byOwner(request()->user()->name)->firstOrFail();
        Client::where('store_id', $store->id)->where('id', $id)->delete();
        return response()->json(['message' => 'Cliente eliminado.']);
    }

    public function tags(Request $request)
    {
        $store = Store::byOwner($request->user()->name)->firstOrFail();
        $tags = Client::where('store_id', $store->id)->whereNotNull('tags')->pluck('tags')
            ->flatten()->unique()->values();
        return response()->json(['data' => $tags]);
    }

    public function syncFromOrders(Request $request)
    {
        $store = Store::byOwner($request->user()->name)->firstOrFail();
        $orders = PurchaseOrder::where('serial', $store->serial)
            ->whereNotNull('nombre')
            ->where('nombre', '!=', '')
            ->get();

        $count = 0;
        $errors = [];

        foreach ($orders as $o) {
            try {
                $client = Client::findByPhoneOrEmail($store->id, $o->tel, null, $o->nombre);
                if (! $client) {
                    $client = Client::create([
                        'store_id' => $store->id,
                        'name' => $o->nombre ?: 'Cliente',
                        'phone' => $o->tel ?: null,
                        'stage' => 'customer',
                        'purchase_count' => 1,
                        'total_spent' => (float) $o->total,
                        'last_purchase_at' => $o->date,
                    ]);
                    $count++;
                } else {
                    $client->increment('purchase_count');
                    $client->increment('total_spent', (float) $o->total);
                    $client->update(['last_purchase_at' => $o->date]);
                    $count++;
                }
            } catch (\Exception $e) {
                $errors[] = $e->getMessage();
            }
        }

        $msg = "$count clientes sincronizados.";
        if (!empty($errors)) {
            $msg .= ' Errores: ' . implode('; ', array_slice($errors, 0, 3));
        }

        return response()->json(['message' => $msg]);
    }
}
