<?php

namespace App\Http\Resources;

use App\Models\OrderPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicOrderProjection extends JsonResource
{
    public function toArray(Request $request): array
    {
        $payment = $this->whenLoaded('payment');
        if (! $payment instanceof OrderPayment) {
            $payment = $this->payment;
        }

        $status = match (true) {
            $payment?->status === 'refund_pending' => 'refund_pending',
            $this->isCancelled() => 'cancelled',
            $this->isPaid() => 'paid',
            default => 'pending',
        };
        $pending = $status === 'pending';

        return [
            'order' => $this->order,
            'status' => $status,
            'payment_method' => $payment?->method,
            'payment_status' => $payment?->status,
            'order_state' => $this->order_state,
            'delivery_type' => $this->delivery_type,
            'amount_due' => $payment ? (string) $payment->amount_due : null,
            'currency' => $payment?->currency,
            'can_pay' => $pending && $payment?->method === 'mercado_pago' && $payment?->status === 'pending',
            'can_cancel' => $pending && $payment instanceof OrderPayment
                && ! in_array($payment->status, ['proof_submitted', 'refund_pending', 'payment_exception'], true),
            'can_submit_proof' => $pending && $payment?->method === 'bank_transfer' && $payment?->status === 'pending',
        ];
    }
}
