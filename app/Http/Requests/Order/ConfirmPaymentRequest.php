<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reference' => ['nullable', 'string', 'max:190'],
            'proof_id' => ['nullable', 'integer', 'min:1'],
            // El dueno declara el metodo SOLO para convertir una orden legada
            // (legacy_unknown -> cash|bank_transfer|cash_on_delivery, one-shot);
            // la accion rechaza 'method' en cualquier otro caso.
            'method' => ['nullable', 'in:cash,bank_transfer,cash_on_delivery'],
            'amount' => ['prohibited'],
            'actor' => ['prohibited'],
        ];
    }
}
