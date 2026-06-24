<?php

namespace Tests\Concerns;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\Traits\CanConfigureMigrationCommands;
use Throwable;

trait RefreshSharedPostgresDatabase
{
    use CanConfigureMigrationCommands;

    protected static bool $sharedTestDatabaseBootstrapped = false;

    protected function refreshSharedPostgresDatabase(): void
    {
        $this->ensureSharedPostgresDatabaseSchema();
        $this->beginSharedDatabaseTransaction();
    }

    protected function ensureSharedPostgresDatabaseSchema(): void
    {
        if (static::$sharedTestDatabaseBootstrapped) {
            return;
        }

        $lockPath = $this->sharedTestDatabaseLockPath();
        $directory = dirname($lockPath);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $handle = fopen($lockPath, 'c+');

        if ($handle === false) {
            throw new \RuntimeException("Unable to open shared test database lock file [{$lockPath}].");
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException("Unable to acquire shared test database lock [{$lockPath}].");
            }

            $signature = $this->sharedTestDatabaseSignature();
            $markerPath = $this->sharedTestDatabaseMarkerPath();
            $storedSignature = is_file($markerPath)
                ? trim((string) file_get_contents($markerPath))
                : null;

            if ($storedSignature !== $signature || ! $this->sharedTestDatabaseLooksReady()) {
                $this->artisan('migrate:fresh', $this->migrateFreshUsing());
                $this->app[Kernel::class]->setArtisan(null);
                file_put_contents($markerPath, $signature);
            }

            static::$sharedTestDatabaseBootstrapped = true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    protected function sharedTestDatabaseLooksReady(): bool
    {
        $connectionName = config('database.default');
        $connection = $this->app['db']->connection($connectionName);
        $schema = $connection->getSchemaBuilder();
        $migrationsTable = $this->migrationTableName();

        if (! $schema->hasTable($migrationsTable)) {
            return false;
        }

        try {
            return $connection->table($migrationsTable)->count() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    protected function migrationTableName(): string
    {
        $migrations = config('database.migrations');

        return is_array($migrations)
            ? ($migrations['table'] ?? 'migrations')
            : $migrations;
    }

    protected function sharedTestDatabaseSignature(): string
    {
        $migrationFiles = glob(database_path('migrations/*.php')) ?: [];

        sort($migrationFiles);

        $payload = [
            'connection' => config('database.default'),
            'database' => config('database.connections.'.config('database.default').'.database'),
            'migrations' => array_map(
                fn (string $path) => basename($path).':'.filemtime($path),
                $migrationFiles
            ),
        ];

        return sha1(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    protected function sharedTestDatabaseLockPath(): string
    {
        return storage_path('framework/testing/shared-postgres-test-db.lock');
    }

    protected function sharedTestDatabaseMarkerPath(): string
    {
        return storage_path('framework/testing/shared-postgres-test-db.signature');
    }

    protected function beginSharedDatabaseTransaction(): void
    {
        $database = $this->app->make('db');
        $connections = $this->connectionsToTransact();

        $this->app->instance('db.transactions', $transactionsManager = new DatabaseTransactionsManager($connections));

        foreach ($connections as $name) {
            $connection = $database->connection($name);
            $dispatcher = $connection->getEventDispatcher();

            $connection->setTransactionManager($transactionsManager);
            $connection->unsetEventDispatcher();
            $connection->beginTransaction();
            $connection->setEventDispatcher($dispatcher);
        }

        $this->beforeApplicationDestroyed(function () use ($database) {
            foreach ($this->connectionsToTransact() as $name) {
                $connection = $database->connection($name);
                $dispatcher = $connection->getEventDispatcher();

                $connection->unsetEventDispatcher();

                if ($connection->getPdo() && $connection->getPdo()->inTransaction()) {
                    $connection->rollBack();
                }

                $connection->setEventDispatcher($dispatcher);
                $connection->disconnect();
            }
        });
    }

    protected function connectionsToTransact()
    {
        return property_exists($this, 'connectionsToTransact')
            ? $this->connectionsToTransact
            : [config('database.default')];
    }
}
