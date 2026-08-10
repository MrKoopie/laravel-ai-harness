<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Drops derived worktree databases through the application's configured server.
 */
class HerdDatabasePruner
{
    public function supported(): bool
    {
        $connection = config('database.default');

        if (! is_string($connection) || $connection === '') {
            return false;
        }

        return in_array(config("database.connections.{$connection}.driver"), ['mysql', 'mariadb'], true);
    }

    /**
     * @param  list<string>  $databases
     */
    public function drop(array $databases): void
    {
        $connectionName = config('database.default');

        if (! is_string($connectionName) || $connectionName === '' || ! $this->supported()) {
            throw new RuntimeException('The configured database connection does not support external database pruning.');
        }

        $schema = DB::connection($connectionName)->getSchemaBuilder();

        foreach ($databases as $database) {
            if (preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
                throw new RuntimeException("Refusing invalid database identifier [{$database}].");
            }

            $schema->dropDatabaseIfExists($database);
        }
    }
}
