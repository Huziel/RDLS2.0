<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Appointment\PublicBookingRequest;
use App\Models\Appointment;
use App\Models\Store;
use App\Models\User;
use App\Services\AppointmentNotificationHtml;
use App\Services\MailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
            'telefono' => $validated['telefono'] ?? '', 'texto' => $validated['texto'] ?? '', 'activo' => 1,
        ]);

        return response()->json(['data' => $a, 'message' => 'Cita creada.'], 201);
    }

    public function destroy($id)
    {
        Appointment::where('idLog', request()->user()->id)->where('id', $id)->delete();

        return response()->json(['message' => 'Cita eliminada.']);
    }

    public function publicStore(PublicBookingRequest $request, string $serial)
    {
        $validated = $request->validated();
        $store = Store::where('serial', $serial)->firstOrFail();
        $owner = User::where('name', $store->createdby)->firstOrFail();

        $a = Appointment::create([
            'idLog' => $owner->id, 'nombre' => $validated['nombre'],
            'fechaCreacion' => now()->format('Y-m-d H:i:s'), 'feachaApartada' => $validated['fecha_apartada'],
            'telefono' => $validated['telefono'], 'texto' => $validated['texto'] ?? '', 'activo' => 1,
        ]);

        $this->notifyAppointment($owner, $a);

        return response()->json(['data' => $a, 'message' => 'Cita agendada.'], 201);
    }

    private function notifyAppointment(User $owner, Appointment $appointment): void
    {
        try {
            $notification = app(AppointmentNotificationHtml::class);
            MailService::send($owner->name, $notification->subject($appointment), $notification->body($appointment));
        } catch (\Exception $e) {
            Log::error('Appointment notification failed: '.$e->getMessage());
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
