<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Delivery\EmitShippingOrderRequest;
use App\Models\Cart;
use App\Models\DeliveryEvidence;
use App\Models\DeliveryLink;
use App\Models\DeliveryLocation;
use App\Models\DeliveryPhoto;
use App\Models\DeliveryProfile;
use App\Models\DeliveryWallet;
use App\Models\PurchaseOrder;
use App\Models\ShippingOrder;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeliveryController extends Controller
{
    // Store owner: emit shipping order
    public function emitOrder(EmitShippingOrderRequest $request, $orderId)
    {
        $user = $request->user();
        $store = Store::byOwner($user->name)->firstOrFail();
        $mode = $request->validated('assignment_mode');
        $deliveryId = $request->validated('delivery_id');

        $result = DB::transaction(function () use ($store, $orderId, $mode, $deliveryId) {
            $purchaseOrder = PurchaseOrder::where('serial', $store->serial)
                ->lockForUpdate()
                ->findOrFail($orderId);

            if ($purchaseOrder->isCancelled()) {
                return ['error' => 'No se puede emitir un envio para una orden cancelada.', 'status' => 409];
            }

            $existing = ShippingOrder::where('ordenCompra', $purchaseOrder->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $sameAssignment = $existing->assignment_mode === $mode
                    && ($mode === 'pool' || (int) $existing->delivery === (int) $deliveryId);

                if (! $sameAssignment) {
                    return ['error' => 'El pedido ya tiene un envio con otra asignacion.', 'status' => 409];
                }

                return ['shipping' => $existing, 'created' => false];
            }

            if ($mode === 'direct') {
                if (! $this->eligibleDeliverer((int) $deliveryId, $store->id, true)) {
                    return ['error' => 'El repartidor no es elegible para esta tienda.', 'status' => 422];
                }

                // FASE 6B P2: un rider con otra entrega activa no puede recibir
                // una asignacion directa (mismo patron otherActive de acceptOrder).
                $otherActive = ShippingOrder::where('delivery', (int) $deliveryId)
                    ->whereIn('status', ['1', '2'])
                    ->lockForUpdate()
                    ->exists();

                if ($otherActive) {
                    return ['error' => 'El repartidor ya tiene una entrega activa.', 'status' => 409];
                }
            }

            $shipping = ShippingOrder::create([
                'tienda' => $store->id,
                'delivery' => $mode === 'direct' ? $deliveryId : null,
                'ordenCompra' => $purchaseOrder->id,
                'fechaIn' => now()->format('Y-m-d H:i:s'),
                'status' => $mode === 'direct' ? '1' : '0',
                'assignment_mode' => $mode,
            ]);

            if ($mode === 'direct') {
                // FASE 6B P0-2: nunca tocar renglones pagados (status='3', marca de pago legacy).
                // La entrega se refleja en ordenenvio.status; el pago en order_state.
                Cart::where('orderC', $purchaseOrder->order)
                    ->where('status', '!=', '3')
                    ->update(['status' => '5']);
            }

            return ['shipping' => $shipping, 'created' => true];
        });

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], $result['status']);
        }

        $shipping = $result['shipping'];
        $created = $result['created'];

        return response()->json([
            'data' => $this->shippingData($shipping),
            'idempotent' => ! $created,
            'message' => $created
                ? ($mode === 'direct' ? 'Envio asignado al repartidor.' : 'Orden de envio emitida.')
                : 'Orden de envio recuperada.',
        ], $created ? 201 : 200);
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

                $walletBalance = DeliveryWallet::where('idLog', $l->deliveryMan)->value('cant') ?? 0;

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
                        'apellidos' => $l->deliver->deliveryProfile->apellidoPaterno.' '.$l->deliver->deliveryProfile->apellidoMaterno,
                        'verificado' => $l->deliver->deliveryProfile->verificado,
                        'foto' => $l->deliver->deliveryProfile->photo->picture ?? null,
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
        if (! $admin->hasRole('super-admin')) {
            abort(403, 'No autorizado.');
        }

        $profile = DeliveryProfile::with('photo')->where('idLog', $userId)->first();
        $wallet = DeliveryWallet::where('idLog', $userId)->value('cant') ?? 0;
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
        if (! $admin->hasRole('super-admin')) {
            abort(403, 'No autorizado.');
        }

        $profile = DeliveryProfile::where('idLog', $userId)->first();
        if (! $profile) {
            return response()->json(['message' => 'El usuario no tiene perfil de repartidor.'], 404);
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

        $profile = DeliveryProfile::where('idLog', $user->id)->where('verificado', '1')->first();
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

        if (! $this->eligibleRider($user)) {
            return response()->json(['message' => 'Repartidor no elegible.'], 403);
        }

        $linkedStoreIds = DeliveryLink::where('deliveryMan', $user->id)->where('bloqueo', '0')->pluck('store');

        $orders = ShippingOrder::with(['purchaseOrder', 'store'])
            ->whereIn('tienda', $linkedStoreIds)
            ->where('assignment_mode', 'pool')
            ->whereNull('delivery')
            ->where('status', '0')
            ->whereDoesntHave('purchaseOrder', fn ($q) => $q->where('order_state', PurchaseOrder::STATE_CANCELLED))
            ->get()
            ->map(fn ($s) => [
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
        $shippingReference = ShippingOrder::findOrFail($shippingId);

        $result = DB::transaction(function () use ($request, $shippingId, $shippingReference) {
            $purchaseOrder = PurchaseOrder::whereKey($shippingReference->ordenCompra)->lockForUpdate()->firstOrFail();
            $shipping = ShippingOrder::whereKey($shippingId)->lockForUpdate()->firstOrFail();
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();

            if (! $this->eligibleDeliverer($user->id, $shipping->tienda, true)) {
                return ['error' => 'Repartidor no elegible para esta tienda.', 'status' => 403];
            }

            if ($purchaseOrder->isCancelled()) {
                return ['error' => 'No se puede aceptar un pedido cancelado.', 'status' => 409];
            }

            if ($shipping->assignment_mode === 'pool'
                && (string) $shipping->status === '1'
                && (int) $shipping->delivery === (int) $user->id) {
                return ['shipping' => $shipping, 'idempotent' => true];
            }

            // FASE 6B P2: el rider no puede tener otra entrega activa.
            $otherActive = ShippingOrder::where('delivery', $user->id)
                ->where('id', '!=', $shipping->id)
                ->whereIn('status', ['1', '2'])
                ->lockForUpdate()
                ->exists();

            if ($otherActive) {
                return ['error' => 'Ya tienes una entrega activa.', 'status' => 409];
            }

            if ($shipping->assignment_mode !== 'pool'
                || (string) $shipping->status !== '0'
                || $shipping->delivery !== null) {
                return ['error' => 'El envio ya no esta disponible.', 'status' => 409];
            }

            $shipping->update(['delivery' => $user->id, 'status' => '1']);
            // FASE 6B P0-2: no tocar renglones pagados (marca de pago legacy).
            Cart::where('orderC', $purchaseOrder->order)
                ->where('status', '!=', '3')
                ->update(['status' => '5']);

            return ['shipping' => $shipping->fresh(), 'idempotent' => false];
        });

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], $result['status']);
        }

        return response()->json([
            'data' => $this->shippingData($result['shipping']),
            'idempotent' => $result['idempotent'],
            'message' => $result['idempotent'] ? 'Pedido ya aceptado.' : 'Pedido aceptado.',
        ]);
    }

    // Completion remains closed until the secure customer OTP flow exists.
    // FASE 6B P1: la barrera es configurable (config/delivery.php) y por
    // defecto esta en false; el comentario dejo de ser la unica proteccion.
    public function completeOrder(Request $request, $shippingId)
    {
        if (! config('delivery.completion_enabled')) {
            return response()->json([
                'message' => 'La confirmacion segura de entrega no esta disponible.',
            ], 503);
        }

        return response()->json([
            'message' => 'La confirmacion segura de entrega no esta disponible.',
        ], 503);
    }

    // Deliver: cancel acceptance
    public function cancelOrder(Request $request, $shippingId)
    {
        $user = $request->user();
        $shippingReference = ShippingOrder::findOrFail($shippingId);

        $result = DB::transaction(function () use ($user, $shippingId, $shippingReference) {
            $purchaseOrder = PurchaseOrder::whereKey($shippingReference->ordenCompra)->lockForUpdate()->firstOrFail();
            $shipping = ShippingOrder::whereKey($shippingId)->lockForUpdate()->firstOrFail();

            if ((int) $shipping->delivery !== (int) $user->id || (string) $shipping->status !== '1') {
                return ['error' => 'Envio no encontrado.', 'status' => 404];
            }

            if ($shipping->assignment_mode !== 'pool') {
                return ['error' => 'Una asignacion directa no puede liberarse al pool.', 'status' => 409];
            }

            $shipping->update(['delivery' => null, 'status' => '0']);
            // FASE 6B P0-2: no tocar renglones pagados (marca de pago legacy).
            Cart::where('orderC', $purchaseOrder->order)
                ->where('status', '!=', '3')
                ->update(['status' => '4']);

            return ['released' => true];
        });

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], $result['status']);
        }

        return response()->json(['message' => 'Pedido liberado.']);
    }

    // Deliver: check active order
    public function activeOrder(Request $request)
    {
        $user = $request->user();
        $shipping = ShippingOrder::with(['purchaseOrder', 'purchaseOrder.shippingForm', 'store'])
            ->where('delivery', $user->id)
            ->whereIn('status', ['1', '2'])
            ->whereDoesntHave('purchaseOrder', fn ($q) => $q->where('order_state', PurchaseOrder::STATE_CANCELLED))
            ->first();

        if (! $shipping) {
            return response()->json(['data' => null, 'message' => 'Sin pedidos activos.']);
        }

        $addr = $shipping->purchaseOrder->shippingForm;

        return response()->json([
            'data' => [
                'id' => $shipping->id,
                'status' => $shipping->status,
                'assignment_mode' => $shipping->assignment_mode,
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
                    'referencia' => $addr ? ($addr->direccion.', '.$addr->ciudad) : ($shipping->purchaseOrder->lat !== '0' ? 'GPS: '.$shipping->purchaseOrder->lat.', '.$shipping->purchaseOrder->long : 'Direccion no disponible'),
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
        $user = $request->user();

        // FASE 6B P0-3 (IDOR GPS): el rider solo puede consultar su propia ubicacion.
        if ((string) $user->type === '3' || $user->hasRole('deliver')) {
            if ((int) $deliverId !== (int) $user->id) {
                return response()->json(['message' => 'Ubicacion no encontrada.'], 404);
            }

            $loc = DeliveryLocation::where('idDeliver', $deliverId)->first();

            return response()->json(['data' => $loc]);
        }

        // El dueno requiere rol store-owner, vinculo del rider en una de sus
        // tiendas y una entrega activa de esa tienda.
        if (! $user->hasRole('store-owner')) {
            return response()->json(['message' => 'Ubicacion no encontrada.'], 404);
        }

        $shopIds = Store::byOwner($user->name)->pluck('id');

        $linked = DeliveryLink::where('deliveryMan', $deliverId)
            ->whereIn('store', $shopIds)
            ->exists();

        if (! $linked) {
            return response()->json(['message' => 'Ubicacion no encontrada.'], 404);
        }

        $active = ShippingOrder::where('delivery', $deliverId)
            ->whereIn('tienda', $shopIds)
            ->whereIn('status', ['1', '2'])
            ->exists();

        if (! $active) {
            return response()->json(['message' => 'Ubicacion no encontrada.'], 404);
        }

        $loc = DeliveryLocation::where('idDeliver', $deliverId)->first();

        return response()->json(['data' => $loc]);
    }

    // Deliver: profile
    public function profile(Request $request)
    {
        $user = $request->user();
        $profile = DeliveryProfile::with('photo')->where('idLog', $user->id)->first();
        $wallet = DeliveryWallet::where('idLog', $user->id)->first();

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

        $profile = DeliveryProfile::where('idLog', $user->id)->first();

        // FASE 6B P0-4: si cambian los documentos de identificacion o del
        // comprobante de domicilio, la verificacion del superusuario deja de
        // corresponder con los documentos y se revoca.
        $documentsChanged = false;
        if ($profile && $request->has('foto_id') && (string) $request->foto_id !== (string) ($profile->fotoID ?? '')) {
            $documentsChanged = true;
        }
        if ($profile && $request->has('foto_domicilio') && (string) $request->foto_domicilio !== (string) ($profile->fotoDomicilio ?? '')) {
            $documentsChanged = true;
        }

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
            'verificado' => $documentsChanged ? '0' : ($profile ? $profile->verificado : '0'),
        ];

        if ($profile) {
            $profile->update($data);
        } else {
            $profile = DeliveryProfile::create($data);
        }

        if ($request->has('foto_perfil')) {
            $photo = DeliveryPhoto::where('idUser', $user->id)->first();
            if ($photo) {
                $photo->update(['picture' => $request->foto_perfil]);
            } else {
                DeliveryPhoto::create(['idUser' => $user->id, 'picture' => $request->foto_perfil]);
            }
        }

        return response()->json(['data' => $profile->load('photo'), 'message' => 'Perfil actualizado.']);
    }

    // Deliver: wallet
    public function wallet(Request $request)
    {
        $user = $request->user();
        $wallet = DeliveryWallet::where('idLog', $user->id)->first();

        return response()->json(['data' => ['balance' => $wallet ? (float) $wallet->cant : 0]]);
    }

    // Deliver: delivery history
    public function deliveryHistory(Request $request)
    {
        $user = $request->user();
        $completed = ShippingOrder::with(['purchaseOrder', 'store'])
            ->where('delivery', $user->id)
            ->where('status', '3')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'fecha' => $s->fechaIn,
                'order_id' => $s->purchaseOrder->order ?? 'N/A',
                'cliente' => $s->purchaseOrder->nombre ?? 'N/A',
                'total' => (float) ($s->purchaseOrder->total ?? 0),
                'envio' => (float) ($s->purchaseOrder->totEnvio ?? 0),
                'store_name' => $s->store->extra->nombreTienda ?? $s->store->serial ?? 'N/A',
                'store_serial' => $s->store->serial ?? 'N/A',
            ]);

        return response()->json(['data' => $completed]);
    }

    // Deliver: upload evidence
    public function uploadEvidence(Request $request)
    {
        $user = $request->user();
        $request->validate(['order_c' => ['required', 'string'], 'image_url' => ['required', 'string']]);

        $orderC = $request->order_c;
        $imageUrl = $request->image_url;

        // FASE 6B P0-1: solo imagenes del propio storage bajo /uploads...
        if (! $this->isValidEvidenceImage($imageUrl)) {
            return response()->json(['message' => 'La imagen debe estar alojada en el storage propio.'], 422);
        }

        $result = DB::transaction(function () use ($user, $orderC, $imageUrl) {
            $purchaseOrder = PurchaseOrder::where('order', $orderC)
                ->lockForUpdate()
                ->first();

            if (! $purchaseOrder) {
                return ['error' => 'Evidencia no permitida para esta orden.', 'status' => 404];
            }

            $shipping = ShippingOrder::where('ordenCompra', $purchaseOrder->id)
                ->lockForUpdate()
                ->first();

            // El rider autenticado debe ser EL asignado y la entrega activa.
            if (! $shipping
                || (int) $shipping->delivery !== (int) $user->id
                || ! in_array((string) $shipping->status, ['1', '2'], true)) {
                return ['error' => 'Evidencia no permitida para esta orden.', 'status' => 404];
            }

            // La evidencia legacy no registra quien la subio ni cuando, asi que
            // no podemos distinguir un reintento idempotente de una subida ajena:
            // cualquier evidencia previa para la orden -> 409 (fail closed).
            if (DeliveryEvidence::where('orderC', $orderC)->exists()) {
                return ['error' => 'Ya existe evidencia de entrega para esta orden.', 'status' => 409];
            }

            DeliveryEvidence::create(['orderC' => $orderC, 'img' => $imageUrl]);

            return ['created' => true];
        });

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], $result['status']);
        }

        return response()->json(['message' => 'Evidencia guardada.']);
    }

    private function isValidEvidenceImage(string $imageUrl): bool
    {
        $path = parse_url($imageUrl, PHP_URL_PATH) ?? '';

        if (! is_string($path) || ! str_starts_with($path, '/uploads/') || str_contains($path, '..')) {
            return false;
        }

        $absolute = public_path(ltrim($path, '/'));

        if (! is_file($absolute)) {
            return false;
        }

        $mime = mime_content_type($absolute);

        return $mime !== false && str_starts_with($mime, 'image/');
    }

    private function eligibleRider(User $user): bool
    {
        return (bool) $user->active
            && (string) $user->type === '3'
            && $user->hasRole('deliver')
            && DeliveryProfile::where('idLog', $user->id)->where('verificado', '1')->exists();
    }

    private function eligibleDeliverer(int $userId, int $storeId, bool $lock = false): bool
    {
        $userQuery = User::whereKey($userId);
        if ($lock) {
            $userQuery->lockForUpdate();
        }

        $user = $userQuery->first();
        if (! $user
            || ! $user->active
            || (string) $user->type !== '3'
            || ! $user->hasRole('deliver')) {
            return false;
        }

        $profileQuery = DeliveryProfile::where('idLog', $userId)->where('verificado', '1');
        $linkQuery = DeliveryLink::where('deliveryMan', $userId)
            ->where('store', $storeId)
            ->where('bloqueo', '0');

        if ($lock) {
            $profileQuery->lockForUpdate();
            $linkQuery->lockForUpdate();
        }

        if ($lock) {
            return $profileQuery->first() !== null && $linkQuery->first() !== null;
        }

        return $profileQuery->exists() && $linkQuery->exists();
    }

    private function shippingData(ShippingOrder $shipping): array
    {
        return [
            'id' => $shipping->id,
            'tienda_id' => $shipping->tienda,
            'delivery_id' => $shipping->delivery,
            'orden_compra_id' => $shipping->ordenCompra,
            'fecha' => $shipping->fechaIn,
            'status' => $shipping->status,
            'assignment_mode' => $shipping->assignment_mode,
        ];
    }
}
