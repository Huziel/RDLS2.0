<?php

namespace App\Http\Requests\Appointment;

use Illuminate\Foundation\Http\FormRequest;

class PublicBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
            'fecha_apartada' => ['required', 'date'],
            'telefono' => ['required', 'string', 'max:50'],
            'texto' => ['nullable', 'string', 'max:5000'],
            'store_serial' => ['prohibited'],
            'store_id' => ['prohibited'],
        ];
    }
}
