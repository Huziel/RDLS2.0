<?php

namespace Tests\Unit;

use App\Support\DestructiveDatabaseCommandGuard;
use Illuminate\Console\Events\CommandStarting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;

class DestructiveDatabaseCommandGuardTest extends TestCase
{
    #[DataProvider('destructiveCommands')]
    public function test_remote_database_commands_are_blocked_without_deliberate_override(string $command): void
    {
        $guard = $this->guard(environment: 'testing', connection: $this->remoteMysql());

        $this->expectException(RuntimeException::class);
        $guard->assertCommandIsSafe($command);
    }

    public function test_production_is_blocked_even_with_an_exact_override(): void
    {
        $guard = $this->guard(
            environment: 'production',
            connection: $this->remoteMysql(),
            allow: true,
            confirmation: 'mysql:mysql:db.example.test:3306/app',
        );

        $this->expectException(RuntimeException::class);
        $guard->assertCommandIsSafe('migrate:fresh');
    }

    public function test_misspelled_production_environment_is_still_blocked(): void
    {
        $guard = $this->guard(
            environment: 'PRODUNCTION',
            connection: $this->remoteMysql(),
            allow: true,
            confirmation: 'mysql:mysql:db.example.test:3306/app',
        );

        $this->expectException(RuntimeException::class);
        $guard->assertCommandIsSafe('db:wipe');
    }

    public function test_database_option_is_validated_instead_of_the_default_connection(): void
    {
        $local = $this->localMysql();
        $guard = new DestructiveDatabaseCommandGuard(
            environment: 'local',
            connectionName: 'mysql',
            connection: $local,
            allowDestructiveCommands: true,
            confirmation: 'mysql:mysql:127.0.0.1:3307/rdls_phase4_test',
            connections: ['mysql' => $local, 'production' => $this->remoteMysql()],
        );
        $event = new CommandStarting(
            'migrate:fresh',
            new StringInput('migrate:fresh --database=production'),
            new NullOutput,
        );

        $this->expectException(RuntimeException::class);
        $guard($event);
    }

    public function test_in_memory_testing_database_is_safe_without_an_override(): void
    {
        $guard = $this->guard(environment: 'testing', connection: [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ], connectionName: 'sqlite');

        $guard->assertCommandIsSafe('migrate:fresh');
        $this->addToAssertionCount(1);
    }

    public function test_disposable_database_requires_an_exact_fingerprint(): void
    {
        $guard = $this->guard(
            environment: 'local',
            connection: $this->localMysql(),
            allow: true,
            confirmation: 'mysql:mysql:127.0.0.1:3307/rdls_phase4_test',
        );

        $guard->assertCommandIsSafe('migrate:refresh');
        $this->assertSame('mysql:mysql:127.0.0.1:3307/rdls_phase4_test', $guard->fingerprint());
    }

    public function test_non_destructive_commands_are_not_restricted(): void
    {
        $guard = $this->guard(environment: 'production', connection: $this->remoteMysql());

        $guard->assertCommandIsSafe('migrate');
        $guard->assertCommandIsSafe('schema:dump');
        $this->addToAssertionCount(2);
    }

    public static function destructiveCommands(): array
    {
        return array_map(fn (string $command) => [$command], [
            'migrate:fresh',
            'db:wipe',
            'migrate:reset',
            'migrate:refresh',
            'migrate:rollback',
        ]);
    }

    private function guard(
        string $environment,
        array $connection,
        bool $allow = false,
        ?string $confirmation = null,
        string $connectionName = 'mysql',
    ): DestructiveDatabaseCommandGuard {
        return new DestructiveDatabaseCommandGuard(
            $environment,
            $connectionName,
            $connection,
            $allow,
            $confirmation,
        );
    }

    private function remoteMysql(): array
    {
        return [
            'driver' => 'mysql',
            'host' => 'db.example.test',
            'port' => '3306',
            'database' => 'app',
        ];
    }

    private function localMysql(): array
    {
        return [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3307',
            'database' => 'rdls_phase4_test',
        ];
    }
}
