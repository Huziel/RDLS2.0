<?php

namespace App\Services;

use App\Models\PurchaseOrder;

class OrderNotificationHtml
{
    public function subject(PurchaseOrder $order): string
    {
        return 'Nueva venta - '.str_replace(["\r", "\n"], '', (string) $order->order);
    }

    public function body(PurchaseOrder $order): string
    {
        $escape = fn (mixed $value): string => htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8',
        );

        return '
            <div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;padding:24px">
                <h2 style="color:#333">Nueva venta registrada</h2>
                <p>Se ha recibido un nuevo pedido en tu tienda:</p>
                <table style="width:100%;border-collapse:collapse;margin:16px 0">
                    <tr><td style="padding:8px;border-bottom:1px solid #eee"><strong>Orden:</strong></td><td style="padding:8px;border-bottom:1px solid #eee">'.$escape($order->order).'</td></tr>
                    <tr><td style="padding:8px;border-bottom:1px solid #eee"><strong>Cliente:</strong></td><td style="padding:8px;border-bottom:1px solid #eee">'.$escape($order->nombre).'</td></tr>
                    <tr><td style="padding:8px;border-bottom:1px solid #eee"><strong>Telefono:</strong></td><td style="padding:8px;border-bottom:1px solid #eee">'.$escape($order->tel).'</td></tr>
                    <tr><td style="padding:8px;border-bottom:1px solid #eee"><strong>Total:</strong></td><td style="padding:8px;border-bottom:1px solid #eee;color:#059669;font-weight:700">$'.$escape(number_format((float) $order->total, 2)).'</td></tr>
                    <tr><td style="padding:8px"><strong>Fecha:</strong></td><td style="padding:8px">'.$escape($order->date).'</td></tr>
                </table>
                <p style="color:#666;font-size:14px">Revisa tu dashboard para mas detalles.</p>
            </div>';
    }
}
