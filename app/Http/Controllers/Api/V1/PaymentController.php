<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\MercadoPago\CreatePreference;
use App\Http\Controllers\Controller;
use App\Models\MercadoPagoAccount;
use App\Models\MercadoPagoPayment;
use App\Models\PurchaseOrder;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        try {
            $merchant = Http::withToken($validated['secret_key'])
                ->acceptJson()
                ->timeout(8)
                ->get('https://api.mercadopago.com/users/me');
        } catch (Throwable $exception) {
            Log::warning('MercadoPago account verification failed.', ['exception' => $exception]);

            return response()->json(['message' => 'No fue posible verificar la cuenta de MercadoPago.'], 502);
        }
        if (! $merchant->successful() || ! is_scalar($merchant->json('id'))) {
            return response()->json(['message' => 'Las credenciales de MercadoPago no son validas.'], 422);
        }

        DB::transaction(function () use ($user, $validated, $merchant) {
            $user->newQuery()->lockForUpdate()->findOrFail($user->id);
            MercadoPagoAccount::updateOrCreate(
                ['idLog' => $user->id],
                [
                    'secretKey' => $validated['secret_key'],
                    'publicKey' => $validated['public_key'],
                    'merchantId' => (string) $merchant->json('id'),
                ],
            );
        });

        return response()->json(['message' => 'Cuenta MercadoPago guardada.']);
    }

    public function getAccount(Request $request)
    {
        $acc = MercadoPagoAccount::where('idLog', $request->user()->id)->first();

        return response()->json(['data' => $acc ? ['has_account' => true] : ['has_account' => false]]);
    }

    public function createPreference(Request $request, $orderId)
    {
        $store = Store::byOwner($request->user()->name)->firstOrFail();
        $order = PurchaseOrder::where('serial', $store->serial)->findOrFail($orderId);
        $acc = MercadoPagoAccount::where('idLog', $request->user()->id)->first();

        if (! $acc || ! $acc->merchantId) {
            return response()->json(['message' => 'Esta tienda debe verificar su cuenta de MercadoPago.'], 422);
        }

        try {
            $preference = app(CreatePreference::class)($order, $acc, $store);
        } catch (Throwable $exception) {
            Log::warning('MercadoPago preference creation failed.', [
                'order' => $order->order,
                'exception' => $exception,
            ]);

            return response()->json(['message' => 'No fue posible crear la preferencia de pago.'], 502);
        }

        return response()->json([
            'data' => [
                'order_id' => $order->order,
                'total' => (float) $order->total + (float) $order->totEnvio,
                'public_key' => $acc->publicKey,
                'store_name' => $store->extra->nombreTienda ?? $store->serial,
                'preference_id' => $preference['preference_id'],
                'init_point' => $preference['init_point'],
                'sandbox_init_point' => $preference['sandbox_init_point'],
            ],
            'message' => 'Preferencia de pago creada.',
        ]);
    }

    public function webhook(Request $request)
    {
        $paymentId = $request->input('data.id');
        if ($request->input('type') !== 'payment' || ! is_scalar($paymentId) || (string) $paymentId === '') {
            return response()->json(['status' => 'ignored']);
        }

        $webhookSecret = (string) config('services.mercadopago.webhook_secret');
        if ($webhookSecret === '') {
            Log::error('MercadoPago webhook credentials are not configured.');

            return response()->json(['message' => 'Webhook no disponible.'], 503);
        }
        if (! $this->hasValidWebhookSignature($request, (string) $paymentId, $webhookSecret)) {
            return response()->json(['message' => 'Firma invalida.'], 401);
        }

        $merchantId = $request->input('user_id');
        $accounts = is_scalar($merchantId)
            ? MercadoPagoAccount::where('merchantId', (string) $merchantId)->get()
            : collect();
        $account = $accounts->first();
        if (! $account) {
            return response()->json(['status' => 'ignored']);
        }

        $cancelled = false;

        try {
            $payment = Http::withToken($account->secretKey)
                ->acceptJson()
                ->timeout(8)
                ->get('https://api.mercadopago.com/v1/payments/'.rawurlencode((string) $paymentId))
                ->throw()
                ->json();

            $orderReference = $payment['external_reference'] ?? null;
            if (! is_string($orderReference) || ($payment['status'] ?? null) !== 'approved') {
                return response()->json(['status' => 'ignored']);
            }

            $order = PurchaseOrder::where('order', $orderReference)->first();
            $reportedAmount = (float) ($payment['transaction_amount'] ?? -1);
            $expectedAmount = $order ? (float) $order->total + (float) $order->totEnvio : -1;
            $storeOwnerId = $order?->store?->owner?->id;
            if (! $order
                || (string) ($payment['collector_id'] ?? '') !== (string) $account->merchantId
                || ! $accounts->contains(fn (MercadoPagoAccount $candidate) => (int) $candidate->idLog === (int) $storeOwnerId)
                || abs($reportedAmount - $expectedAmount) > 0.009) {
                Log::warning('MercadoPago webhook order or amount mismatch.', [
                    'payment_id' => (string) $paymentId,
                    'order_reference' => $orderReference,
                ]);

                return response()->json(['status' => 'ignored']);
            }

            DB::transaction(function () use ($order, $orderReference, $paymentId, &$cancelled) {
                $fresh = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

                // FASE 6B P2: un webhook tardio no puede resucitar una orden
                // cancelada. Sin mutaciones y respuesta de ignorado (200).
                if ($fresh->isCancelled()) {
                    $cancelled = true;

                    return;
                }

                $payment = MercadoPagoPayment::where('orderP', $orderReference)->lockForUpdate()->first();
                if (! $payment || (int) $payment->status === 1) {
                    return;
                }

                $payment->update([
                    'status' => '1',
                    'payment_id' => (int) $paymentId,
                    'fecha' => now()->format('Y-m-d H:i:s'),
                ]);
                $fresh->cartItems()
                    ->where('variation', $fresh->serial)
                    ->where('status', '!=', '3')
                    ->update(['status' => '3']);
                $fresh->update(['order_state' => PurchaseOrder::STATE_PAID]);
            });
        } catch (Throwable $exception) {
            Log::error('MercadoPago webhook verification failed.', [
                'payment_id' => (string) $paymentId,
                'exception' => $exception,
            ]);

            return response()->json(['message' => 'No fue posible verificar el pago.'], 502);
        }

        if ($cancelled) {
            return response()->json(['status' => 'ignored']);
        }

        return response()->json(['status' => 'ok']);
    }

    private function hasValidWebhookSignature(Request $request, string $paymentId, string $secret): bool
    {
        $requestId = (string) $request->header('X-Request-Id', '');
        $signature = (string) $request->header('X-Signature', '');
        preg_match('/(?:^|,)\s*ts=([^,]+)/', $signature, $timestampMatch);
        preg_match('/(?:^|,)\s*v1=([a-fA-F0-9]+)/', $signature, $hashMatch);

        if ($requestId === '' || ! isset($timestampMatch[1], $hashMatch[1])) {
            return false;
        }

        $manifest = "id:{$paymentId};request-id:{$requestId};ts:{$timestampMatch[1]};";

        return hash_equals(hash_hmac('sha256', $manifest, $secret), strtolower($hashMatch[1]));
    }
}
