<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\ExtraCharge;
use App\Models\OrderPayment;
use App\Models\PurchaseOrder;
use DomainException;

class CanonicalOrderAmount
{
    /** @return array{products_amount:string,discount_amount:string,shipping_amount:string,extra_amount:string,amount_due:string} */
    public function calculate(PurchaseOrder $order, bool $lock = false): array
    {
        $cart = Cart::where('orderC', $order->order)->orderBy('id');
        $extras = ExtraCharge::where('orderP', $order->order)->orderBy('id');
        if ($lock) {
            $cart->lockForUpdate();
            $extras->lockForUpdate();
        }

        $products = $cart->get()->sum(fn (Cart $item) => $this->cents($item->price));
        $discount = $this->cents($order->loyalty_discount ?? 0);
        $shipping = $this->cents($order->totEnvio ?? 0);
        $extra = $extras->get()->sum(fn (ExtraCharge $charge) => $this->cents($charge->precio));
        $due = $products - $discount + $shipping + $extra;

        if ($due < 0) {
            throw new DomainException('El importe canonico no puede ser negativo.');
        }
        if ($this->cents($order->total) !== $products - $discount) {
            throw new DomainException('El total de productos de la orden no coincide con los datos del servidor.');
        }

        return [
            'products_amount' => $this->money($products),
            'discount_amount' => $this->money($discount),
            'shipping_amount' => $this->money($shipping),
            'extra_amount' => $this->money($extra),
            'amount_due' => $this->money($due),
        ];
    }

    public function snapshot(PurchaseOrder $order, OrderPayment $payment, bool $freeze, bool $lock = false): OrderPayment
    {
        if ($payment->frozen_at !== null) {
            $current = $this->calculate($order, $lock);
            if ($this->cents($payment->amount_due) !== $this->cents($current['amount_due'])) {
                throw new DomainException('El importe congelado no coincide con la orden.');
            }

            return $payment;
        }

        $values = $this->calculate($order, $lock);
        if ($freeze) {
            $values['frozen_at'] = now();
        }
        $payment->update($values);

        return $payment->refresh();
    }

    public function cents(mixed $amount): int
    {
        $value = trim((string) $amount);
        if (! preg_match('/^\d{1,12}(?:\.(\d{1,2}))?$/', $value, $matches)) {
            throw new DomainException('El importe debe ser decimal(14,2) no negativo y tener maximo dos decimales.');
        }
        $fraction = str_pad($matches[1] ?? '', 2, '0');
        $cents = ((int) explode('.', $value, 2)[0] * 100) + (int) $fraction;
        if ($cents > 99999999999999) {
            throw new DomainException('El importe excede decimal(14,2).');
        }

        return $cents;
    }

    public function money(int $cents): string
    {
        if ($cents < 0 || $cents > 99999999999999) {
            throw new DomainException('El importe excede decimal(14,2).');
        }

        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
