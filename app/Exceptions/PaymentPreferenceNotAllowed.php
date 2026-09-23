<?php

namespace App\Exceptions;

use RuntimeException;

final class PaymentPreferenceNotAllowed extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function forNonMercadoPagoOrder(): self
    {
        return new self('La orden no fue creada para MercadoPago.');
    }

    public static function forOrderState(): self
    {
        return new self('La orden no admite una preferencia de pago.');
    }
}
