<?php

namespace App\Http\Requests\Delivery;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmitShippingOrderRequest extends FormRequest
{
    /**
     * Compatibilidad con el bundle legacy.
     *
     * DEPRECADO desde FASE 6B: la UI fuente (FASE 7) debe enviar
     * 'assignment_mode' explicito. Mientras tanto se infiere del payload:
     * 'delivery_id' presente => 'direct'; ausente => 'pool'.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('assignment_mode')) {
            $this->merge([
                'assignment_mode' => $this->has('delivery_id') ? 'direct' : 'pool',
            ]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assignment_mode' => ['required', 'string', Rule::in(['pool', 'direct'])],
            'delivery_id' => [
                Rule::requiredIf($this->input('assignment_mode') === 'direct'),
                Rule::prohibitedIf($this->input('assignment_mode') === 'pool'),
                'integer',
                'min:1',
            ],
        ];
    }
}
