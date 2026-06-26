<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\DeliveryLink;
use App\Models\DeliveryLocation;
use App\Models\PurchaseOrder;
use App\Models\ShippingOrder;
use App\Models\Store;
use App\Models\VerificationCode;
use Illuminate\Http\Request;

class DeliveryController extends Controller
{
    // Store owner: emit shipping order
    public function emitOrder(Request $request, $orderId)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        $purchaseOrder = PurchaseOrder::findOrFail($orderId);

        // Allow emit even without GPS - use 0,0 as fallback
        if (!$purchaseOrder->lat || !$purchaseOrder->long || $purchaseOrder->lat === '0') {
            $purchaseOrder->update(['lat' => '0', 'long' => '0']);
        }

        $existing = ShippingOrder::where('ordenCompra', $purchaseOrder->id)->first();
        if ($existing) {
            return response()->json(['message' => 'Ya existe una orden de envio para este pedido.'], 422);
        }

        $assignedDeliver = $request->input('delivery_id');

        $shipping = ShippingOrder::create([
            'tienda' => $store->id,
            'delivery' => $assignedDeliver,
            'ordenCompra' => $purchaseOrder->id,
            'fechaIn' => now()->format('Y-m-d H:i:s'),
            'status' => $assignedDeliver ? '1' : '0',
        ]);

        // If assigned to specific driver, mark cart as accepted
        if ($assignedDeliver) {
            Cart::where('orderC', $purchaseOrder->order)->update(['status' => '5']);
            VerificationCode::create([
                'orderC' => $purchaseOrder->order,
                'code' => str_pad(mt_rand(0, 999), 3, '0', STR_PAD_LEFT),
            ]);
        }

        $deliverIds = DeliveryLink::where('store', $store->id)
            ->where('bloqueo', '0')
            ->pluck('deliveryMan');

        return response()->json([
            'data' => $shipping,
            'notified_deliverers' => $assignedDeliver ? 1 : $deliverIds->count(),
            'message' => $assignedDeliver ? 'Envio asignado al repartidor.' : 'Orden de envio emitida.',
        ]);
    }

    // Store owner: view linked deliverers
    public function linkedDeliverers(Request $request)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        $links = DeliveryLink::with(['deliver:id,name', 'deliver.deliveryProfile', 'deliver.deliveryProfile.photo'])
            ->where('store', $store->id)
            ->get()
            ->map(function ($l) use ($store) {
                // Calculate earnings for this driver from this store
                $completedOrders = ShippingOrder::where('tienda', $store->id)
                    ->where('delivery', $l->deliveryMan)
                    ->where('status', '3')
                    ->with('purchaseOrder')
                    ->get();

                $totalEarnings = $completedOrders->sum(function ($so) {
                    return (float) ($so->purchaseOrder->totEnvio ?? 0);
                });

                $walletBalance = \App\Models\DeliveryWallet::where('idLog', $l->deliveryMan)->value('cant') ?? 0;

                return [
                    'id' => $l->id,
                    'bloqueo' => $l->bloqueo,
                    'deliver_id' => $l->deliveryMan,
                    'deliver_name' => $l->deliver->name ?? '',
                    'total_earnings' => $totalEarnings,
                    'pending_balance' => $walletBalance,
                    'completed_deliveries' => $completedOrders->count(),
                    'profile' => $l->deliver->deliveryProfile ? [
                        'nombre' => $l->deliver->deliveryProfile->nombre,
                        'apellidos' => $l->deliver->deliveryProfile->apellidoPaterno . ' ' . $l->deliver->deliveryProfile->apellidoMaterno,
                        'verificado' => $l->deliver->deliveryProfile->verificado,
                        'foto' => $l->deliver->deliveryProfile->photo->picture ?? null,
                        'foto_id' => $l->deliver->deliveryProfile->fotoID ?? null,
                        'foto_domicilio' => $l->deliver->deliveryProfile->fotoDomicilio ?? null,
                        'placas' => $l->deliver->deliveryProfile->placas ?? null,
                        'vehiculo' => $l->deliver->deliveryProfile->tipo ?? null,
                    ] : null,
                ];
            });

        return response()->json(['data' => $links]);
    }

    // Admin: get deliverer full profile
    public function adminGetDeliverer(Request $request, $userId)
    {
        $admin = $request->user();
        if (!$admin->hasRole('super-admin')) {
            abort(403, 'No autorizado.');
        }

        $profile = \App\Models\DeliveryProfile::with('photo')->where('idLog', $userId)->first();
        $wallet = \App\Models\DeliveryWallet::where('idLog', $userId)->value('cant') ?? 0;
        $linkedStores = DeliveryLink::where('deliveryMan', $userId)->get()
            ->map(function ($l) {
                $store = Store::find($l->store);
                return [
                    'id' => $l->id,
                    'store_id' => $store ? $store->id : $l->store,
                    'store_serial' => $store ? $store->serial : null,
                    'store_name' => $store ? ($store->extra->nombre_tienda ?? null) : null,
                    'blocked' => $l->bloqueo == '1',
                ];
            });

        return response()->json(['data' => [
            'profile' => $profile,
            'wallet' => $wallet,
            'linked_stores' => $linkedStores,
        ]]);
    }

    // Admin: verify/unverify a deliverer
    public function adminToggleVerify(Request $request, $userId)
    {
        $admin = $request->user();
        if (!$admin->hasRole('super-admin')) {
            abort(403, 'No autorizado.');
        }

        $profile = \App\Models\DeliveryProfile::where('idLog', $userId)->first();
        if (!$profile) {
            return response()->json(['message' => 'El usuario no tiene perfil de repartidor.'], 404);
        }

        $newStatus = $profile->verificado == '1' ? '0' : '1';
        $profile->update(['verificado' => $newStatus]);

        return response()->json([
            'message' => $newStatus == '1' ? 'Repartidor verificado.' : 'Verificacion revocada.',
            'data' => ['verificado' => $newStatus],
        ]);
    }

    // Store owner: verify/unverify a deliverer
    public function verifyDeliver(Request $request, $linkId)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();
        $link = DeliveryLink::where('store', $store->id)->findOrFail($linkId);
        $profile = \App\Models\DeliveryProfile::where('idLog', $link->deliveryMan)->first();

        if (!$profile) {
            return response()->json(['message' => 'El repartidor no tiene perfil.'], 404);
        }

        $newStatus = $profile->verificado == '1' ? '0' : '1';
        $profile->update(['verificado' => $newStatus]);

        return response()->json([
            'message' => $newStatus == '1' ? 'Repartidor verificado.' : 'Verificacion revocada.',
            'data' => ['verificado' => $newStatus],
        ]);
    }

    // Store owner: block/unblock deliverer
    public function toggleBlock(Request $request, $linkId)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();

        $link = DeliveryLink::where('store', $store->id)->findOrFail($linkId);
        $link->update(['bloqueo' => $link->bloqueo == '1' ? '0' : '1']);

        return response()->json([
            'data' => $link,
            'message' => $link->bloqueo == '1' ? 'Repartidor bloqueado.' : 'Repartidor desbloqueado.',
        ]);
    }

    // Deliver: attach to store
    public function attachStore(Request $request)
    {
        $request->validate(['store_id' => ['required', 'string']]);

        $user = $request->user();

        if ($user->type != '3') {
            return response()->json(['message' => 'Solo repartidores pueden anexarse a tiendas.'], 403);
        }

        $profile = \App\Models\DeliveryProfile::where('idLog', $user->id)->where('verificado', '1')->first();
        if (! $profile) {
            return response()->json(['message' => 'Debes estar verificado para anexarte a una tienda.'], 403);
        }

        // Accept either numeric ID or serial string
        $storeId = $request->store_id;
        if (is_numeric($storeId)) {
            $store = Store::find($storeId);
        } else {
            $store = Store::where('serial', $storeId)->first();
        }

        if (! $store) {
            return response()->json(['message' => 'Tienda no encontrada. Verifica el codigo.'], 404);
        }

        $exists = DeliveryLink::where('deliveryMan', $user->id)->where('store', $store->id)->first();
        if ($exists) {
            return response()->json(['message' => 'Ya estas vinculado a esta tienda.'], 422);
        }

        DeliveryLink::create(['deliveryMan' => $user->id, 'store' => $store->id, 'bloqueo' => '0']);

        return response()->json(['message' => 'Te has anexado a la tienda exitosamente.']);
    }

    public function detachStore(Request $request, $linkId)
    {
        $user = $request->user();
        DeliveryLink::where('deliveryMan', $user->id)->where('id', $linkId)->delete();
        return response()->json(['message' => 'Desvinculado de la tienda.']);
    }

    // Deliver: view attached stores
    public function myStores(Request $request)
    {
        $user = $request->user();
        $links = DeliveryLink::where('deliveryMan', $user->id)->get();
        $data = $links->map(function ($l) {
            $store = Store::find($l->store);
            return [
                'id' => $l->id,
                'store_id' => $store ? $store->id : $l->store,
                'store_serial' => $store ? $store->serial : null,
                'store_name' => $store ? ($store->extra->nombre_tienda ?? null) : null,
                'store_phone' => $store ? $store->phone : null,
                'store_adress' => $store ? $store->adress : null,
                'store_lat' => $store ? $store->lat : null,
                'store_lng' => $store ? $store->long : null,
                'blocked' => $l->bloqueo == '1',
            ];
        });
        return response()->json(['data' => $data]);
    }

    // Deliver: available orders
    public function availableOrders(Request $request)
    {
        $user = $request->user();
        $linkedStoreIds = DeliveryLink::where('deliveryMan', $user->id)->where('bloqueo', '0')->pluck('store');

        $orders = ShippingOrder::with(['purchaseOrder', 'store'])
            ->whereIn('tienda', $linkedStoreIds)
            ->where('status', '0')
            ->get()
            ->map(fn($s) => [
                'id' => $s->id,
                'orden_compra_id' => $s->ordenCompra,
                'tienda_id' => $s->tienda,
                'fecha' => $s->fechaIn,
                'order' => $s->purchaseOrder ? [
                    'order_id' => $s->purchaseOrder->order,
                    'cliente' => $s->purchaseOrder->nombre,
                    'telefono' => $s->purchaseOrder->tel,
                    'total' => (float) $s->purchaseOrder->total,
                    'envio' => (float) $s->purchaseOrder->totEnvio,
                    'lat' => $s->purchaseOrder->lat,
                    'lng' => $s->purchaseOrder->long,
                ] : null,
            ]);

        return response()->json(['data' => $orders]);
    }

    // Deliver: accept order
    public function acceptOrder(Request $request, $shippingId)
    {
        $user = $request->user();
        $shipping = ShippingOrder::findOrFail($shippingId);

        $shipping->update(['delivery' => $user->id, 'status' => '1']);

        $code = str_pad(mt_rand(0, 999), 3, '0', STR_PAD_LEFT);
        VerificationCode::create(['orderC' => $shipping->purchaseOrder->order, 'code' => $code]);

        \App\Models\Cart::where('orderC', $shipping->purchaseOrder->order)->update(['status' => '5']);

        return response()->json(['data' => ['code' => $code], 'message' => 'Pedido aceptado.']);
    }

    // Deliver: complete order with verification code
    public function completeOrder(Request $request, $shippingId)
    {
        $user = $request->user();
        $request->validate(['code' => ['required', 'string']]);

        $shipping = ShippingOrder::where('delivery', $user->id)->findOrFail($shippingId);
        $orderC = $shipping->purchaseOrder->order;

        $verify = VerificationCode::where('orderC', $orderC)->where('code', $request->code)->first();
        if (! $verify) {
            return response()->json(['message' => 'Código de verificación incorrecto.'], 422);
        }

        $shipping->update(['status' => '3']);
        \App\Models\Cart::where('orderC', $orderC)->update(['status' => '7']);

        // Add to wallet
        $wallet = \App\Models\DeliveryWallet::firstOrNew(['idLog' => $user->id]);
        $wallet->cant = ($wallet->cant ?? 0) + $shipping->purchaseOrder->totEnvio;
        $wallet->time = now()->format('Y-m-d H:i:s');
        $wallet->save();

        return response()->json(['message' => 'Pedido entregado. Código verificado.']);
    }

    // Deliver: cancel acceptance
    public function cancelOrder(Request $request, $shippingId)
    {
        $user = $request->user();
        $shipping = ShippingOrder::where('delivery', $user->id)->where('status', '1')->findOrFail($shippingId);

        $shipping->update(['delivery' => null, 'status' => '0']);
        \App\Models\Cart::where('orderC', $shipping->purchaseOrder->order)->update(['status' => '4']);

        return response()->json(['message' => 'Pedido liberado.']);
    }

    // Deliver: check active order
    public function activeOrder(Request $request)
    {
        $user = $request->user();
        $shipping = ShippingOrder::with(['purchaseOrder', 'purchaseOrder.shippingForm', 'store'])
            ->where('delivery', $user->id)
            ->whereIn('status', ['1', '2'])
            ->first();

        if (! $shipping) {
            return response()->json(['data' => null, 'message' => 'Sin pedidos activos.']);
        }

        $verify = VerificationCode::where('orderC', $shipping->purchaseOrder->order)->first();
        $addr = $shipping->purchaseOrder->shippingForm;

        return response()->json([
            'data' => [
                'id' => $shipping->id,
                'status' => $shipping->status,
                'code' => $verify->code ?? null,
                'store' => [
                    'adress' => $shipping->store->adress ?? null,
                    'lat' => $shipping->store->lat && $shipping->store->lat !== '0' ? (float) $shipping->store->lat : null,
                    'lng' => $shipping->store->long && $shipping->store->long !== '0' ? (float) $shipping->store->long : null,
                ],
                'order' => [
                    'order_id' => $shipping->purchaseOrder->order,
                    'cliente' => $shipping->purchaseOrder->nombre,
                    'telefono' => $shipping->purchaseOrder->tel,
                    'total' => (float) $shipping->purchaseOrder->total,
                    'envio' => (float) $shipping->purchaseOrder->totEnvio,
                    'lat' => $shipping->purchaseOrder->lat !== '0' ? (float) $shipping->purchaseOrder->lat : null,
                    'lng' => $shipping->purchaseOrder->long !== '0' ? (float) $shipping->purchaseOrder->long : null,
                    'direccion' => $addr ? $addr->direccion : null,
                    'ciudad' => $addr ? $addr->ciudad : null,
                    'codigo_postal' => $addr ? $addr->codigoPostal : null,
                    'referencia' => $addr ? ($addr->direccion . ', ' . $addr->ciudad) : ($shipping->purchaseOrder->lat !== '0' ? 'GPS: ' . $shipping->purchaseOrder->lat . ', ' . $shipping->purchaseOrder->long : 'Direccion no disponible'),
                ],
            ],
        ]);
    }

    // Deliver: location update
    public function updateLocation(Request $request)
    {
        $user = $request->user();
        $request->validate(['lat' => ['required', 'numeric'], 'lng' => ['required', 'numeric']]);

        $loc = DeliveryLocation::where('idDeliver', $user->id)->first();
        if ($loc) {
            $loc->update(['latitud' => $request->lat, 'longitud' => $request->lng, 'time' => now()->format('Y-m-d H:i:s')]);
        } else {
            DeliveryLocation::create(['idDeliver' => $user->id, 'latitud' => $request->lat, 'longitud' => $request->lng, 'time' => now()->format('Y-m-d H:i:s')]);
        }

        return response()->json(['message' => 'Ubicación actualizada.']);
    }

    public function getLocation(Request $request, $deliverId)
    {
        $loc = DeliveryLocation::where('idDeliver', $deliverId)->first();
        return response()->json(['data' => $loc]);
    }

    // Deliver: profile
    public function profile(Request $request)
    {
        $user = $request->user();
        $profile = \App\Models\DeliveryProfile::with('photo')->where('idLog', $user->id)->first();
        $wallet = \App\Models\DeliveryWallet::where('idLog', $user->id)->first();

        return response()->json([
            'data' => [
                'profile' => $profile,
                'wallet' => $wallet ? (float) $wallet->cant : 0,
            ],
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'nombre' => ['required', 'string'],
            'apellido_paterno' => ['required', 'string'],
            'apellido_materno' => ['nullable', 'string'],
            'fecha_nacimiento' => ['required', 'date'],
            'placas' => ['nullable', 'string'],
            'tipo_vehiculo' => ['nullable', 'string'],
            'modelo' => ['nullable', 'string'],
            'color_vehiculo' => ['nullable', 'string'],
            'foto_perfil' => ['nullable', 'string'],
            'foto_id' => ['nullable', 'string'],
            'foto_domicilio' => ['nullable', 'string'],
        ]);

        $profile = \App\Models\DeliveryProfile::where('idLog', $user->id)->first();

        $data = [
            'nombre' => $validated['nombre'],
            'apellidoPaterno' => $validated['apellido_paterno'],
            'apellidoMaterno' => $validated['apellido_materno'] ?? null,
            'fechaNacimiento' => $validated['fecha_nacimiento'],
            'placas' => $validated['placas'] ?? null,
            'tipo' => $validated['tipo_vehiculo'] ?? null,
            'modelo' => $validated['modelo'] ?? null,
            'color' => $validated['color_vehiculo'] ?? null,
            'fotoPorfile' => $validated['foto_perfil'] ?? null,
            'fotoID' => $validated['foto_id'] ?? null,
            'fotoDomicilio' => $validated['foto_domicilio'] ?? null,
            'idLog' => $user->id,
            'verificado' => $profile ? $profile->verificado : '0',
        ];

        if ($profile) {
            $profile->update($data);
        } else {
            $profile = \App\Models\DeliveryProfile::create($data);
        }

        if ($request->has('foto_perfil')) {
            $photo = \App\Models\DeliveryPhoto::where('idUser', $user->id)->first();
            if ($photo) $photo->update(['picture' => $request->foto_perfil]);
            else \App\Models\DeliveryPhoto::create(['idUser' => $user->id, 'picture' => $request->foto_perfil]);
        }

        return response()->json(['data' => $profile->load('photo'), 'message' => 'Perfil actualizado.']);
    }

    // Deliver: wallet
    public function wallet(Request $request)
    {
        $user = $request->user();
        $wallet = \App\Models\DeliveryWallet::where('idLog', $user->id)->first();
        return response()->json(['data' => ['balance' => $wallet ? (float) $wallet->cant : 0]]);
    }

    // Deliver: upload evidence
    public function uploadEvidence(Request $request)
    {
        $user = $request->user();
        $request->validate(['order_c' => ['required', 'string'], 'image_url' => ['required', 'string']]);

        $evidence = \App\Models\DeliveryEvidence::where('orderC', $request->order_c)->first();
        if ($evidence) {
            $evidence->update(['img' => $request->image_url]);
        } else {
            \App\Models\DeliveryEvidence::create(['orderC' => $request->order_c, 'img' => $request->image_url]);
        }

        return response()->json(['message' => 'Evidencia guardada.']);
    }

    // Public: customer delivery tracking (session-based)
    public function customerTrack(Request $request)
    {
        $sessionId = $request->session()->getId();

        // Find active deliveries for this session's orders
        $cartOrders = Cart::where('user', $sessionId)
            ->whereNotNull('orderC')
            ->pluck('orderC')
            ->unique();

        if ($cartOrders->isEmpty()) {
            return response()->json(['data' => null]);
        }

        // Find the most recent active shipping order
        $shipping = ShippingOrder::whereIn('ordenCompra', $cartOrders)
            ->whereIn('status', ['0', '1'])
            ->orderByDesc('id')
            ->first();

        if (!$shipping) {
            return response()->json(['data' => null]);
        }

        // Get verification code
        $verification = VerificationCode::where('orderC', $shipping->ordenCompra)->first();
        $purchaseOrder = PurchaseOrder::where('order', $shipping->ordenCompra)->first();

        return response()->json(['data' => [
            'shipping_id' => $shipping->id,
            'order_id' => $shipping->ordenCompra,
            'status' => $shipping->status,
            'status_label' => $shipping->status == '1' ? 'En camino' : 'Pendiente de repartidor',
            'code' => $verification?->code ?? null,
            'cliente' => $purchaseOrder?->nombre,
            'total' => $purchaseOrder?->total,
            'fecha' => $purchaseOrder?->date,
        ]]);
    }
}
