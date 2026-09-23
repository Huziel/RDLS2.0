<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\MercadoPago\CreatePreference;
use App\Exceptions\IdempotencyConflict;
use App\Http\Controllers\Controller;
use App\Models\MercadoPagoAccount;
use App\Models\MercadoPagoPayment;
use App\Models\OrderPayment;
use App\Models\OrderProviderTransaction;
use App\Models\PurchaseOrder;
use App\Models\Store;
use App\Services\CanonicalOrderAmount;
use App\Services\OrderIdempotency;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class PaymentController extends Controller
{
    // Save MP credentials
    public function saveAccount(Request $request)
    {
        abort_if($request->user()->hasRole('super-admin'), 403, 'Superadmin es solo lectura para finanzas de ordenes.');
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

        try {
            $saved = DB::transaction(function () use ($user, $validated, $merchant) {
                $user->newQuery()->lockForUpdate()->findOrFail($user->id);
                $merchantId = (string) $merchant->json('id');
                if (MercadoPagoAccount::where('merchantId', $merchantId)
                    ->where('idLog', '!=', $user->id)
                    ->lockForUpdate()
                    ->exists()) {
                    return false;
                }
                MercadoPagoAccount::updateOrCreate(
                    ['idLog' => $user->id],
                    [
                        'secretKey' => $validated['secret_key'],
                        'publicKey' => $validated['public_key'],
                        'merchantId' => $merchantId,
                    ],
                );

                return true;
            });
        } catch (QueryException) {
            $saved = false;
        }
        if (! $saved) {
            return response()->json(['message' => 'La cuenta MercadoPago ya esta vinculada a otra tienda.'], 409);
        }

        return response()->json(['message' => 'Cuenta MercadoPago guardada.']);
    }

    public function getAccount(Request $request)
    {
        $acc = MercadoPagoAccount::where('idLog', $request->user()->id)->first();

        return response()->json(['data' => $acc ? ['has_account' => true] : ['has_account' => false]]);
    }

    public function createPreference(Request $request, $orderId)
    {
        abort_if($request->user()->hasRole('super-admin'), 403, 'Superadmin es solo lectura para finanzas de ordenes.');
        $store = Store::byOwner($request->user()->name)->firstOrFail();
        $order = PurchaseOrder::where('serial', $store->serial)->findOrFail($orderId);
        if (! $order->payment || $order->payment->method !== 'mercado_pago') {
            return response()->json(['message' => 'La orden no fue creada para MercadoPago.'], 422);
        }
        $acc = MercadoPagoAccount::where('idLog', $request->user()->id)->first();

        if (! $acc || ! $acc->merchantId) {
            return response()->json(['message' => 'Esta tienda debe verificar su cuenta de MercadoPago.'], 422);
        }

        try {
            $preference = app(CreatePreference::class)(
                $order,
                $acc,
                $store,
                app(OrderIdempotency::class)->key($request),
                $request->user()->id,
                'store_owner',
            );
        } catch (IdempotencyConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (ValidationException $exception) {
            // La cabecera Idempotency-Key faltante o invalida es un error del
            // cliente: 422 accionable, jamas 502 por una cabecera ausente.
            return response()->json([
                'message' => $exception->validator->errors()->first(),
                'errors' => $exception->errors(),
            ], 422);
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
                'total' => (float) $order->payment->amount_due,
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
        $account = is_scalar($merchantId)
            ? MercadoPagoAccount::where('merchantId', (string) $merchantId)->first()
            : null;
        if (! $account) {
            return response()->json(['status' => 'ignored']);
        }

        try {
            $providerData = Http::withToken($account->secretKey)
                ->acceptJson()
                ->timeout(8)
                ->get('https://api.mercadopago.com/v1/payments/'.rawurlencode((string) $paymentId))
                ->throw()
                ->json();

            $orderReference = $providerData['external_reference'] ?? null;
            if (! is_string($orderReference) || ($providerData['status'] ?? null) !== 'approved') {
                return response()->json(['status' => 'ignored']);
            }

            $order = PurchaseOrder::with('payment')->where('order', $orderReference)->first();
            $storeOwnerId = $order?->store?->owner?->id;
            // Una orden legada (legacy_unknown) se acepta SOLO con evidencia
            // MercadoPago: una preferencia historica o una transaccion MP ya
            // registrada (re-notificacion del payment_id backfilled). Sin
            // evidencia real el webhook no puede atribuir el cobro.
            $isMercadoPago = $order?->payment?->method === 'mercado_pago';
            $hasLegacyMpEvidence = $order?->payment?->method === 'legacy_unknown'
                && (MercadoPagoPayment::where('orderP', $orderReference)->exists()
                    || OrderProviderTransaction::where('order_id', $order->id)->where('provider', 'mercado_pago')->exists());
            if (! $order
                || ! $order->payment
                || (! $isMercadoPago && ! $hasLegacyMpEvidence)
                || ($order->payment->frozen_at === null && ! $hasLegacyMpEvidence)
                || (string) ($providerData['currency_id'] ?? '') !== 'MXN'
                || (string) ($providerData['collector_id'] ?? '') !== (string) $account->merchantId
                || (int) $account->idLog !== (int) $storeOwnerId
                || app(CanonicalOrderAmount::class)->cents($providerData['transaction_amount'] ?? -1) <= 0) {
                Log::warning('MercadoPago webhook order or amount mismatch.', [
                    'payment_id' => (string) $paymentId,
                    'order_reference' => $orderReference,
                ]);

                return response()->json(['status' => 'ignored']);
            }

            DB::transaction(function () use ($order, $orderReference, $paymentId, $providerData) {
                $fresh = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $fresh->shippingOrder()->lockForUpdate()->first();
                $canonical = OrderPayment::where('order_id', $fresh->id)->lockForUpdate()->firstOrFail();
                $fresh->returnRecord()->lockForUpdate()->first();
                $fresh->setRelation('payment', $canonical);
                $existingTransaction = OrderProviderTransaction::where('provider', 'mercado_pago')
                    ->where('provider_payment_id', (string) $paymentId)
                    ->lockForUpdate()
                    ->first();
                if ($existingTransaction) {
                    // Re-notificacion de un payment_id ya registrado (p. ej. el
                    // backfill legacy): canonizar el metodo sin duplicar filas.
                    if ($canonical->method === 'legacy_unknown') {
                        $canonical->update([
                            'method' => 'mercado_pago',
                            'frozen_at' => $canonical->frozen_at ?? now(),
                        ]);
                    }

                    return;
                }

                $previousStatus = $canonical->status;
                $amounts = app(CanonicalOrderAmount::class);
                $remoteCents = $amounts->cents($providerData['transaction_amount']);
                if ($canonical->frozen_at === null) {
                    // Orden legada pendiente: congelar el importe al momento del
                    // cobro con los datos actuales (locks) antes de fijar el pago.
                    $canonical = $amounts->snapshot($fresh, $canonical, true, true);
                }
                $legacy = MercadoPagoPayment::where('orderP', $orderReference)->lockForUpdate()->first();
                OrderProviderTransaction::create([
                    'payment_id' => $canonical->id,
                    'store_id' => $canonical->store_id,
                    'order_id' => $fresh->id,
                    'provider' => 'mercado_pago',
                    'provider_payment_id' => (string) $paymentId,
                    'preference_id' => $legacy?->preference,
                    'amount' => $amounts->money($remoteCents),
                    'currency' => $canonical->currency,
                    'remote_status' => 'approved',
                ]);
                // Un cobro previo sin provider que ya representa fondos cobrados
                // se materializa como transaccion 'manual' (append-only) con su
                // referencia, para que paidCents sea contable y monotono en cada
                // re-notificacion en lugar de una suma fantasma.
                if ($canonical->provider === null && $canonical->representsCollectedFunds()) {
                    OrderProviderTransaction::query()->insertOrIgnore([
                        'payment_id' => $canonical->id,
                        'store_id' => $canonical->store_id,
                        'order_id' => $fresh->id,
                        'provider' => 'manual',
                        'provider_payment_id' => 'carryforward:'.$canonical->id,
                        'amount' => $canonical->amount_paid,
                        'currency' => $canonical->currency,
                        'remote_status' => 'approved',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                $accumulatedCents = OrderProviderTransaction::where('payment_id', $canonical->id)
                    ->whereIn('provider', ['mercado_pago', 'manual'])
                    ->where('remote_status', 'approved')
                    ->get(['amount'])
                    ->sum(fn (OrderProviderTransaction $transaction) => $amounts->cents($transaction->amount));
                // Monotono: nunca retroceder por debajo del amount_paid ya
                // registrado; solo se excede ante un doble cobro real.
                $paidCents = max($amounts->cents($canonical->amount_paid), $accumulatedCents);
                $dueCents = $amounts->cents($canonical->amount_due);
                $fullyPaid = $paidCents >= $dueCents;
                $overpaid = $paidCents > $dueCents;
                $needsRefund = $fresh->isCancelled() || $overpaid;
                $nextStatus = match (true) {
                    $fresh->isCancelled() => 'refund_pending',
                    $overpaid => 'payment_exception',
                    default => $fullyPaid ? 'paid' : 'pending',
                };
                $canonical->update([
                    'status' => $nextStatus,
                    'method' => 'mercado_pago',
                    'amount_paid' => $amounts->money($paidCents),
                    'paid_at' => $fullyPaid ? ($canonical->paid_at ?? now()) : null,
                    'provider' => 'mercado_pago',
                    'provider_payment_id' => $canonical->provider_payment_id ?? (string) $paymentId,
                    'refund_requested_at' => $needsRefund ? ($canonical->refund_requested_at ?? now()) : $canonical->refund_requested_at,
                ]);
                $legacy?->update([
                    'status' => $fullyPaid ? '1' : '0',
                    'payment_id' => $legacy->payment_id ?? (int) $paymentId,
                    'fecha' => now()->format('Y-m-d H:i:s'),
                ]);
                if ($fullyPaid) {
                    // Los renglones devueltos (status 5) no vuelven a marcarse pagados.
                    $fresh->cartItems()
                        ->where('variation', $fresh->serial)
                        ->where('status', '!=', '5')
                        ->update(['status' => '3']);
                }
                if ($fullyPaid && ! $fresh->isCancelled()) {
                    $fresh->update(['order_state' => PurchaseOrder::STATE_PAID]);
                }

                $idempotency = app(OrderIdempotency::class);
                $eventKey = $idempotency->eventKey($fresh, 'mercado-pago-payment', (string) $paymentId);
                if (! $idempotency->find($eventKey, $idempotency->hash(['provider_payment_id' => (string) $paymentId]))) {
                    $idempotency->record(
                        $fresh,
                        (int) $canonical->store_id,
                        null,
                        'mercado_pago',
                        $fresh->isCancelled()
                            ? 'payment_refund_required'
                            : ($overpaid ? 'payment_exception' : 'mercado_pago_approved'),
                        $eventKey,
                        $idempotency->hash(['provider_payment_id' => (string) $paymentId]),
                        ['payment_status' => $previousStatus],
                        ['payment_status' => $nextStatus, 'cancelled' => $fresh->isCancelled(), 'amount_paid' => $amounts->money($paidCents)],
                        200,
                        ['status' => 'ok'],
                    );
                }
            });
        } catch (Throwable $exception) {
            Log::error('MercadoPago webhook verification failed.', [
                'payment_id' => (string) $paymentId,
                'exception' => $exception,
            ]);

            return response()->json(['message' => 'No fue posible verificar el pago.'], 502);
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
