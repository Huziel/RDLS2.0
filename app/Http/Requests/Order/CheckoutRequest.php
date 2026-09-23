<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:200'],
            'telefono' => ['required', 'string', 'max:50'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'costo_envio' => ['nullable', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            'direccion' => ['nullable', 'string', 'required_if:tipo_envio,shipping,national'],
            'ciudad' => ['nullable', 'string'],
            'codigo_postal' => ['nullable', 'string'],
            'loyalty_points' => ['prohibited'],
            'tipo_envio' => ['required', 'string', 'in:pickup,shipping,national'],
            'payment_method' => ['required', 'string', 'in:cash,bank_transfer,cash_on_delivery,mercado_pago'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('payment_method') === 'cash_on_delivery' && $this->input('tipo_envio') !== 'shipping') {
                $validator->errors()->add('payment_method', 'El pago contra entrega solo esta disponible para entrega local.');
            }
        });
    }
}
