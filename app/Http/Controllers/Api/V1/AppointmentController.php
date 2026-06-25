<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\MailService;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    public function index(Request $request)
    {
        $appointments = Appointment::where('idLog', $request->user()->id)
            ->where('activo', 1)->orderByDesc('id')->get();
        return response()->json(['data' => $appointments]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre' => 'required|string', 'fecha_apartada' => 'required|date',
            'telefono' => 'nullable|string', 'texto' => 'nullable|string',
        ]);
        $a = Appointment::create([
            'idLog' => $request->user()->id, 'nombre' => $validated['nombre'],
            'fechaCreacion' => now()->format('Y-m-d H:i:s'), 'feachaApartada' => $validated['fecha_apartada'],
            'telefono' => $validated['telefono'] ?? null, 'texto' => $validated['texto'] ?? null, 'activo' => 1,
        ]);
        return response()->json(['data' => $a, 'message' => 'Cita creada.'], 201);
    }

    public function destroy($id)
    {
        Appointment::where('idLog', request()->user()->id)->where('id', $id)->delete();
        return response()->json(['message' => 'Cita eliminada.']);
    }

    public function publicStore(Request $request)
    {
        $validated = $request->validate([
            'nombre' => 'required|string', 'fecha_apartada' => 'required|date',
            'telefono' => 'required|string', 'texto' => 'nullable|string',
            'store_serial' => 'required|string',
        ]);
        $store = \App\Models\Store::where('serial', $validated['store_serial'])->firstOrFail();
        $owner = \App\Models\User::where('name', $store->createdby)->firstOrFail();

        $a = Appointment::create([
            'idLog' => $owner->id, 'nombre' => $validated['nombre'],
            'fechaCreacion' => now()->format('Y-m-d H:i:s'), 'feachaApartada' => $validated['fecha_apartada'],
            'telefono' => $validated['telefono'], 'texto' => $validated['texto'] ?? null, 'activo' => 1,
        ]);

        $this->notifyAppointment($owner, $a);

        return response()->json(['data' => $a, 'message' => 'Cita agendada.'], 201);
    }

    private function notifyAppointment($owner, $appointment)
    {
        try {
            $subject = "Nueva cita - {$appointment->nombre}";
            $body = '
            <div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;padding:24px">
                <h2 style="color:#333">Nueva cita agendada</h2>
                <p>Se ha registrado una nueva cita en tu tienda:</p>
                <table style="width:100%;border-collapse:collapse;margin:16px 0">
                    <tr><td style="padding:8px;border-bottom:1px solid #eee"><strong>Cliente:</strong></td><td style="padding:8px;border-bottom:1px solid #eee">'.$appointment->nombre.'</td></tr>
                    <tr><td style="padding:8px;border-bottom:1px solid #eee"><strong>Telefono:</strong></td><td style="padding:8px;border-bottom:1px solid #eee">'.($appointment->telefono ?? 'N/A').'</td></tr>
                    <tr><td style="padding:8px;border-bottom:1px solid #eee"><strong>Fecha:</strong></td><td style="padding:8px;border-bottom:1px solid #eee">'.$appointment->feachaApartada.'</td></tr>
                    '.($appointment->texto ? '<tr><td style="padding:8px"><strong>Notas:</strong></td><td style="padding:8px">'.$appointment->texto.'</td></tr>' : '').'
                </table>
                <p style="color:#666;font-size:14px">Revisa tu dashboard para mas detalles.</p>
            </div>';

            MailService::send($owner->name, $subject, $body);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Appointment notification failed: ' . $e->getMessage());
        }
    }

    public function availability(Request $request)
    {
        $date = $request->get('fecha', now()->format('Y-m-d'));
        $count = Appointment::where('idLog', $request->user()->id)
            ->whereDate('feachaApartada', $date)->where('activo', 1)->count();
        return response()->json(['data' => ['todos_ocupados' => $count >= 10, 'ocupados' => $count]]);
    }
}
