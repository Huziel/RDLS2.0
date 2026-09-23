<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Delivery completion
    |--------------------------------------------------------------------------
    |
    | La confirmacion segura de entrega requiere el flujo OTP del cliente
    | aprobado para FASE 8/9 (6 digitos, expiracion e intentos limitados).
    | La asignacion hibrida pool/direct ya fue aprobada en FASE 6.
    | Mientras la flag permanezca en false, completeOrder responde 503 y la
    | barrera deja de depender de un comentario en el controlador.
    |
    */
    'completion_enabled' => (bool) env('DELIVERY_COMPLETION_ENABLED', false),
];
