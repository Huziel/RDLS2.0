<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Order\ConfirmFullRefund;
use App\Actions\Order\ReceiveFullReturn;
use App\Actions\Order\SubmitTransferProof;
use App\Exceptions\IdempotencyConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\RefundRequest;
use App\Http\Requests\Order\ReturnRequest;
use App\Http\Requests\Order\TransferProofRequest;
use App\Models\OrderAuditEvent;
use App\Models\OrderPaymentProof;
use App\Models\PurchaseOrder;
use App\Models\Store;
use App\Services\OrderIdempotency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class OrderFinanceController extends Controller
{
    public function submitProof(
        TransferProofRequest $request,
        string $serial,
        string $reference,
        SubmitTransferProof $submit,
        OrderIdempotency $idempotency,
    ) {
        $token = $request->header('X-Cart-Token');
        if (! is_string($token) || trim($token) === '') {
            abort(404, 'Orden no encontrada.');
        }
        $store = Store::where('serial', $serial)->firstOrFail();
        $order = PurchaseOrder::where('serial', $serial)->where('session', $token)
            ->where(fn ($query) => is_numeric($reference) ? $query->whereKey($reference) : $query->where('order', $reference))
            ->firstOrFail();

        try {
            $result = $submit(
                $order,
                $store->id,
                $request->file('proof'),
                $request->validated('reference'),
                $idempotency->key($request),
            );
        } catch (IdempotencyConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], $result['status_code']);
        }

        return response()->json(['data' => $result], $result['idempotent'] ? 200 : 201);
    }

    public function refund(RefundRequest $request, int $id, ConfirmFullRefund $refund, OrderIdempotency $idempotency)
    {
        $this->denySuperAdminMutation($request);
        $store = Store::byOwner($request->user()->name)->firstOrFail();
        $order = PurchaseOrder::where('serial', $store->serial)->findOrFail($id);
        try {
            $result = $refund($order, $store->id, $request->user()->id, $idempotency->key($request), $request->validated('reference'));
        } catch (IdempotencyConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return isset($result['error'])
            ? response()->json(['message' => $result['error']], $result['status_code'])
            : response()->json(['data' => $result]);
    }

    public function receiveReturn(ReturnRequest $request, int $id, ReceiveFullReturn $receive, OrderIdempotency $idempotency)
    {
        $this->denySuperAdminMutation($request);
        $store = Store::byOwner($request->user()->name)->firstOrFail();
        $order = PurchaseOrder::where('serial', $store->serial)->findOrFail($id);
        try {
            $result = $receive($order, $store->id, $request->user()->id, $idempotency->key($request), $request->boolean('restockable'));
        } catch (IdempotencyConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return isset($result['error'])
            ? response()->json(['message' => $result['error']], $result['status_code'])
            : response()->json(['data' => $result]);
    }

    public function proofs(Request $request, int $id)
    {
        $store = Store::byOwner($request->user()->name)->firstOrFail();
        $order = PurchaseOrder::where('serial', $store->serial)->findOrFail($id);

        return response()->json(['data' => $order->payment?->proofs()->get(['id', 'mime', 'size', 'reference', 'created_at']) ?? []]);
    }

    public function download(Request $request, int $id, int $proof)
    {
        $store = Store::byOwner($request->user()->name)->firstOrFail();
        $order = PurchaseOrder::where('serial', $store->serial)->findOrFail($id);

        return $this->proofDownload($order, $proof, $store->id);
    }

    public function adminDownload(Request $request, int $id, int $proof)
    {
        abort_unless($request->user()->hasRole('super-admin'), 403);
        $order = PurchaseOrder::findOrFail($id);

        return $this->proofDownload($order, $proof, (int) $order->store?->id);
    }

    public function audit(Request $request, int $id)
    {
        $user = $request->user();
        if ($user->hasRole('super-admin')) {
            $order = PurchaseOrder::findOrFail($id);
        } else {
            $store = Store::byOwner($user->name)->firstOrFail();
            $order = PurchaseOrder::where('serial', $store->serial)->findOrFail($id);
        }

        return response()->json(['data' => OrderAuditEvent::where('order_id', $order->id)
            ->orderBy('id')->get(['id', 'actor_type', 'event_type', 'previous', 'next', 'response_status', 'response_data', 'created_at'])]);
    }

    private function proofDownload(PurchaseOrder $order, int $proofId, int $storeId)
    {
        $payment = $order->payment;
        if (! $payment || (int) $payment->store_id !== $storeId) {
            abort(404);
        }
        $proof = OrderPaymentProof::where('payment_id', $payment->id)->where('store_id', $storeId)->findOrFail($proofId);
        if (! Storage::disk($proof->disk)->exists($proof->path)) {
            abort(404);
        }

        return Storage::disk($proof->disk)->download(
            $proof->path,
            'comprobante-'.$proof->id.'.'.$this->extension($proof->mime),
            [
                'Content-Type' => $proof->mime,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
        );
    }

    private function extension(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'pdf',
        };
    }

    private function denySuperAdminMutation(Request $request): void
    {
        abort_if($request->user()->hasRole('super-admin'), 403, 'Superadmin es solo lectura para finanzas de ordenes.');
    }
}
