<?php

namespace App\Services;

use App\Models\Appointment;

class AppointmentNotificationHtml
{
    public function subject(Appointment $appointment): string
    {
        return 'Nueva cita - '.htmlspecialchars(
            str_replace(["\r", "\n"], '', (string) $appointment->nombre),
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8',
        );
    }

    public function body(Appointment $appointment): string
    {
        $escape = fn (mixed $value): string => htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8',
        );
        $notes = $appointment->texto
            ? '<tr><td style="padding:8px"><strong>Notas:</strong></td><td style="padding:8px">'.$escape($appointment->texto).'</td></tr>'
            : '';

        return '
            <div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;padding:24px">
                <h2 style="color:#333">Nueva cita agendada</h2>
                <p>Se ha registrado una nueva cita en tu tienda:</p>
                <table style="width:100%;border-collapse:collapse;margin:16px 0">
                    <tr><td style="padding:8px;border-bottom:1px solid #eee"><strong>Cliente:</strong></td><td style="padding:8px;border-bottom:1px solid #eee">'.$escape($appointment->nombre).'</td></tr>
                    <tr><td style="padding:8px;border-bottom:1px solid #eee"><strong>Telefono:</strong></td><td style="padding:8px;border-bottom:1px solid #eee">'.$escape($appointment->telefono ?: 'N/A').'</td></tr>
                    <tr><td style="padding:8px;border-bottom:1px solid #eee"><strong>Fecha:</strong></td><td style="padding:8px;border-bottom:1px solid #eee">'.$escape($appointment->feachaApartada).'</td></tr>
                    '.$notes.'
                </table>
                <p style="color:#666;font-size:14px">Revisa tu dashboard para mas detalles.</p>
            </div>';
    }
}
