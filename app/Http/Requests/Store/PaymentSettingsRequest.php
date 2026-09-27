<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

class PaymentSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cash_on_delivery_enabled' => ['required', 'boolean'],
            'store_id' => ['prohibited'],
        ];
    }
}
