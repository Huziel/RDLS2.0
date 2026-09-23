<?php

namespace App\Providers;

use App\Support\DestructiveDatabaseCommandGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('proof-uploads', function (Request $request) {
            // El limiter devuelve DOS limites: el middleware throttling los
            // aplica en conjunto (fail-closed). El segundo agrega una capa por
            // IP: un cliente no puede evadir el tope por orden rotando ordenes
            // desde la misma IP. Ambos registran el intento ANTES de ejecutar
            // el controlador, asi que los 422 por tope de orden (3 comprobantes
            // / 30 MiB) tambien consumen la capa por IP (proteccion fail-closed
            // contra fuerza bruta).
            return [
                Limit::perMinute(5)->by(implode('|', [
                    $request->ip(),
                    $request->route('serial'),
                    $request->route('order'),
                ])),
                Limit::perMinute(20)->by((string) $request->ip()),
            ];
        });

        if (! $this->app->runningInConsole()) {
            return;
        }

        $connectionName = (string) config('database.default');
        $guard = new DestructiveDatabaseCommandGuard(
            environment: $this->app->environment(),
            connectionName: $connectionName,
            connection: (array) config("database.connections.{$connectionName}", []),
            allowDestructiveCommands: (bool) config('database-safety.allow_destructive_commands', false),
            confirmation: config('database-safety.destructive_confirmation'),
            connections: (array) config('database.connections', []),
        );

        Event::listen(CommandStarting::class, $guard);
    }
}
