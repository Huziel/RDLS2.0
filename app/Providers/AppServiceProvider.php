<?php

namespace App\Providers;

use App\Support\DestructiveDatabaseCommandGuard;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
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
