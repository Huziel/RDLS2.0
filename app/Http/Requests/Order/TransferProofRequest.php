<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class TransferProofRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:190'],
            'proof' => ['required', 'file', 'max:30720', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf'],
        ];
    }
}
