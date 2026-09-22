<?php

namespace App\Support;

use Illuminate\Console\Events\CommandStarting;
use RuntimeException;

class DestructiveDatabaseCommandGuard
{
    private const DESTRUCTIVE_COMMANDS = [
        'db:wipe',
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'migrate:rollback',
    ];

    public function __construct(
        private readonly string $environment,
        private readonly string $connectionName,
        private readonly array $connection,
        private readonly bool $allowDestructiveCommands,
        private readonly ?string $confirmation,
        private readonly array $connections = [],
    ) {}

    public function __invoke(CommandStarting $event): void
    {
        $connectionName = $event->input->getParameterOption('--database', $this->connectionName, true);
        if (is_string($connectionName) && $connectionName !== '' && $connectionName !== $this->connectionName) {
            $guard = new self(
                environment: $this->environment,
                connectionName: $connectionName,
                connection: (array) ($this->connections[$connectionName] ?? []),
                allowDestructiveCommands: $this->allowDestructiveCommands,
                confirmation: $this->confirmation,
                connections: $this->connections,
            );
            $guard->assertCommandIsSafe((string) $event->command);

            return;
        }

        $this->assertCommandIsSafe((string) $event->command);
    }

    public function assertCommandIsSafe(string $command): void
    {
        if (! in_array($command, self::DESTRUCTIVE_COMMANDS, true)) {
            return;
        }

        if ($this->environment === 'testing' && $this->isInMemorySqlite()) {
            return;
        }

        if (str_starts_with(strtolower($this->environment), 'prod')) {
            throw new RuntimeException("Database command [{$command}] is disabled in production.");
        }

        $fingerprint = $this->fingerprint();
        if (! $this->allowDestructiveCommands
            || ! is_string($this->confirmation)
            || ! hash_equals($fingerprint, $this->confirmation)) {
            throw new RuntimeException(
                "Database command [{$command}] is blocked for [{$fingerprint}]. "
                .'Set ALLOW_DESTRUCTIVE_DB_COMMANDS=true and DESTRUCTIVE_DB_CONFIRMATION to that exact fingerprint only for a disposable database.'
            );
        }
    }

    public function fingerprint(): string
    {
        $target = $this->resolvedTarget();

        if ($target['driver'] === 'sqlite') {
            return "{$this->connectionName}:sqlite:{$target['database']}";
        }

        return sprintf(
            '%s:%s:%s:%s/%s',
            $this->connectionName,
            $target['driver'],
            $target['host'],
            $target['port'],
            $target['database'],
        );
    }

    private function isInMemorySqlite(): bool
    {
        $target = $this->resolvedTarget();

        return $target['driver'] === 'sqlite' && $target['database'] === ':memory:';
    }

    private function resolvedTarget(): array
    {
        $driver = (string) ($this->connection['driver'] ?? $this->connectionName);
        $host = (string) ($this->connection['host'] ?? 'localhost');
        $port = (string) ($this->connection['port'] ?? '');
        $database = (string) ($this->connection['database'] ?? '');
        $url = $this->connection['url'] ?? null;

        if (is_string($url) && $url !== '') {
            $parts = parse_url($url);
            if (is_array($parts)) {
                $driver = (string) ($parts['scheme'] ?? $driver);
                $host = (string) ($parts['host'] ?? $host);
                $port = (string) ($parts['port'] ?? $port);
                $database = isset($parts['path']) ? ltrim($parts['path'], '/') : $database;
            }
        }

        return compact('driver', 'host', 'port', 'database');
    }
}
