<?php

namespace App\Actions\Order;

use App\Exceptions\IdempotencyConflict;
use App\Models\OrderAuditEvent;
use App\Models\OrderPayment;
use App\Models\OrderPaymentProof;
use App\Models\PurchaseOrder;
use App\Services\CanonicalOrderAmount;
use App\Services\OrderIdempotency;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SubmitTransferProof
{
    public function __construct(private readonly OrderIdempotency $idempotency) {}

    public function __invoke(
        PurchaseOrder $order,
        int $storeId,
        UploadedFile $file,
        string $reference,
        string $key,
    ): array {
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
        ];
        if (! isset($extensions[$mime]) || $file->getSize() === false || $file->getSize() > 30 * 1024 * 1024) {
            throw ValidationException::withMessages(['proof' => ['El comprobante no tiene un formato permitido.']]);
        }

        $isImage = in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            && @getimagesize($file->getRealPath()) !== false;
        $header = file_get_contents($file->getRealPath(), false, null, 0, 5);
        $isPdf = $mime === 'application/pdf' && $header === '%PDF-';
        if (! $isImage && ! $isPdf) {
            throw ValidationException::withMessages(['proof' => ['El contenido del comprobante no coincide con su formato.']]);
        }

        // Read and hash before acquiring database locks.
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false) {
            throw ValidationException::withMessages(['proof' => ['No fue posible leer el comprobante.']]);
        }
        $sha256 = hash('sha256', $contents);
        $requestHash = $this->idempotency->hash(['reference' => $reference, 'sha256' => $sha256]);
        $eventKey = $this->idempotency->eventKey($order, 'transfer-proof', $key);
        $existing = OrderAuditEvent::where('event_key', $eventKey)->first();
        if ($existing) {
            if (! hash_equals($existing->request_hash, $requestHash)) {
                throw new IdempotencyConflict('La clave de idempotencia ya fue utilizada con otro contenido.');
            }

            return ['proof_id' => $existing->response_data['proof_id'], 'status' => 'proof_submitted', 'idempotent' => true];
        }

        $path = $order->serial.'/'.$order->id.'/'.Str::uuid().'.'.$extensions[$mime];
        if (! Storage::disk('payment-proofs')->put($path, $contents)) {
            throw new RuntimeException('No fue posible almacenar el comprobante privado.');
        }

        try {
            $result = DB::transaction(function () use ($order, $storeId, $path, $mime, $file, $sha256, $reference, $eventKey, $requestHash) {
                $fresh = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $payment = OrderPayment::where('order_id', $fresh->id)->lockForUpdate()->firstOrFail();
                if ((int) $payment->store_id !== $storeId || $payment->method !== 'bank_transfer') {
                    throw ValidationException::withMessages(['payment' => ['La orden no admite comprobantes de transferencia.']]);
                }
                if ($fresh->isCancelled() || in_array($payment->status, OrderPayment::PAID_STATUSES, true)) {
                    return ['error' => 'La orden ya no admite comprobantes.', 'status_code' => 409];
                }

                $proofs = OrderPaymentProof::where('payment_id', $payment->id)->orderBy('id')->lockForUpdate()->get();
                $event = $this->idempotency->find($eventKey, $requestHash);
                if ($event) {
                    Storage::disk('payment-proofs')->delete($path);

                    return ['proof_id' => $event->response_data['proof_id'], 'status' => 'proof_submitted', 'idempotent' => true];
                }
                if ($proofs->count() >= 3 || $proofs->sum('size') + (int) $file->getSize() > 30 * 1024 * 1024) {
                    return ['error' => 'Se permiten hasta 3 comprobantes y 30 MiB acumulados.', 'status_code' => 422];
                }

                // Congelar importes ANTES de fijar proof_submitted: el monto
                // debe quedar inmutable al momento exacto de la revision.
                $payment = app(CanonicalOrderAmount::class)->snapshot($fresh, $payment, true, true);
                $proof = OrderPaymentProof::create([
                    'payment_id' => $payment->id,
                    'store_id' => $storeId,
                    'disk' => 'payment-proofs',
                    'path' => $path,
                    'mime' => $mime,
                    'size' => (int) $file->getSize(),
                    'sha256' => $sha256,
                    'reference' => $reference,
                ]);
                $payment->update([
                    'status' => 'proof_submitted',
                    'bank_reference' => $reference,
                    'frozen_at' => $payment->frozen_at ?? now(),
                ]);
                $response = ['proof_id' => $proof->id, 'status' => 'proof_submitted'];
                $this->idempotency->record(
                    $fresh, $storeId, null, 'customer', 'transfer_proof_submitted', $eventKey,
                    $requestHash, ['status' => 'pending'], ['status' => 'proof_submitted'], 201, $response,
                );

                return $response + ['idempotent' => false];
            });
            if (isset($result['error'])) {
                Storage::disk('payment-proofs')->delete($path);
            }

            return $result;
        } catch (\Throwable $exception) {
            Storage::disk('payment-proofs')->delete($path);
            throw $exception;
        }
    }
}
