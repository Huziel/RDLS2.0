<?php

namespace App\Enums;

enum UserType: string
{
    case StoreOwner = '1';
    case Deliver = '3';
    case Customer = '4';

    public function label(): string
    {
        return match ($this) {
            self::StoreOwner => 'Dueño de tienda',
            self::Deliver => 'Repartidor',
            self::Customer => 'Cliente',
        };
    }

    public function abilities(): array
    {
        return match ($this) {
            self::StoreOwner => [
                'store:manage',
                'products:manage',
                'orders:manage',
                'pos:use',
                'pos:history',
                'coupons:manage',
                'delivery:manage',
                'appointments:manage',
                'barters:manage',
                'ai-studio:use',
                'web-studio:use',
                'invoicing:use',
            ],
            self::Deliver => [
                'delivery:accept',
                'delivery:deliver',
                'delivery:profile',
                'delivery:wallet',
            ],
            self::Customer => [
                'cart:use',
                'orders:create',
                'orders:history',
            ],
        };
    }
}
