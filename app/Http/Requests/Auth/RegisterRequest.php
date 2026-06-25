<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'phone' => ['required', 'string', 'max:20'],
            'category' => ['nullable', 'string', 'max:10'],
            'type' => ['required', 'string', 'in:1,3,4'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.max' => 'El correo no debe exceder los 255 caracteres.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 6 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'phone.required' => 'El número de teléfono es obligatorio.',
            'type.required' => 'El tipo de usuario es obligatorio.',
            'type.in' => 'El tipo de usuario no es válido.',
        ];
    }
}
