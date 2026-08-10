<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Drops derived worktree databases through the application's configured server.
 */
class HerdDatabasePruner
{
    /**
     * Determine whether the configured connection supports database removal.
     */
    public function supported(): bool
    {
        $connection = config('database.default');

        if (! is_string($connection) || $connection === '') {
            return false;
        }

        return in_array(config("database.connections.{$connection}.driver"), ['mysql', 'mariadb'], true);
    }

    /**
     * Drop confirmed derived databases while protecting the active database.
     *
     * @param  list<string>  $databases
     */
    public function drop(array $databases): void
    {
        $connectionName = config('database.default');

        if (! is_string($connectionName) || $connectionName === '' || ! $this->supported()) {
            throw new RuntimeException('The configured database connection does not support external database pruning.');
        }

        $connection = $this->connection($connectionName);
        $activeDatabase = $connection->getDatabaseName();
        $schema = $connection->getSchemaBuilder();

        foreach ($databases as $database) {
            if (preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
                throw new RuntimeException("Refusing invalid database identifier [{$database}].");
            }

            if ($database === $activeDatabase) {
                throw new RuntimeException("Refusing to drop the active database [{$database}].");
            }

            $schema->dropDatabaseIfExists($database);
        }
    }

    /**
     * Resolve the configured connection once so the safety check and drops use
     * the same server session.
     */
    protected function connection(string $name): Connection
    {
        return DB::connection($name);
    }
}
