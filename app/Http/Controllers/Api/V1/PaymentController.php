<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MercadoPagoAccount;
use App\Models\MercadoPagoPayment;
use App\Models\PurchaseOrder;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    // Save MP credentials
    public function saveAccount(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'secret_key' => ['required', 'string'],
            'public_key' => ['required', 'string'],
        ]);

        $acc = MercadoPagoAccount::where('idLog', $user->id)->first();
        if ($acc) {
            $acc->update(['secretKey' => $validated['secret_key'], 'publicKey' => $validated['public_key']]);
        } else {
            MercadoPagoAccount::create(['idLog' => $user->id, 'secretKey' => $validated['secret_key'], 'publicKey' => $validated['public_key']]);
        }

        return response()->json(['message' => 'Cuenta MercadoPago guardada.']);
    }

    public function getAccount(Request $request)
    {
        $acc = MercadoPagoAccount::where('idLog', $request->user()->id)->first();
        return response()->json(['data' => $acc ? ['has_account' => true] : ['has_account' => false]]);
    }

    // Create preference for an order (public, multi-tenant)
    public function createPreference(Request $request, $orderId)
    {
        $order = PurchaseOrder::findOrFail($orderId);
        $store = Store::where('serial', $order->serial)->firstOrFail();
        $owner = User::where('name', $store->createdby)->firstOrFail();
        $acc = MercadoPagoAccount::where('idLog', $owner->id)->first();

        if (! $acc) return response()->json(['message' => 'Esta tienda no tiene MercadoPago configurado.'], 422);

        $existing = MercadoPagoPayment::where('orderP', $order->order)->first();
        if (! $existing) {
            MercadoPagoPayment::create(['orderP' => $order->order, 'status' => '0', 'preference' => '', 'fecha' => now()->format('Y-m-d H:i:s')]);
        }

        return response()->json([
            'data' => [
                'order_id' => $order->order,
                'total' => (float) $order->total,
                'public_key' => $acc->publicKey,
                'store_name' => $store->extra->nombreTienda ?? $store->serial,
            ],
            'message' => 'Preferencia de pago creada.',
        ]);
    }

    // Webhook
    public function webhook(Request $request)
    {
        $data = $request->all();

        if (($data['type'] ?? '') === 'payment') {
            $paymentId = $data['data']['id'] ?? null;
            if ($paymentId) {
                // Try to get payment details from MercadoPago API
                try {
                    $ch = curl_init("https://api.mercadopago.com/v1/payments/{$paymentId}");
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . env('MERCADOPAGO_ACCESS_TOKEN', '')]);
                    $response = json_decode(curl_exec($ch), true);
                    curl_close($ch);

                    $orderRef = $response['external_reference'] ?? null;
                    if ($orderRef && ($response['status'] ?? '') === 'approved') {
                        MercadoPagoPayment::where('orderP', $orderRef)->update(['status' => '1', 'fecha' => now()->format('Y-m-d H:i:s')]);
                        // Auto-confirm cart items
                        \App\Models\Cart::where('orderC', $orderRef)->update(['status' => '3']);
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('MP webhook error: ' . $e->getMessage());
                }
            }
        }

        return response()->json(['status' => 'ok']);
    }
}
