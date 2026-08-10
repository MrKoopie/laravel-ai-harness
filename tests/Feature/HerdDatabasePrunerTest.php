<?php

use Illuminate\Database\Connection;
use Illuminate\Database\SQLiteConnection;
use MrKoopie\LaravelAiHarness\Support\HerdDatabasePruner;

test('database pruner refuses to drop the active database', function (): void {
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'), 'active_database');
    $pruner = new class($connection) extends HerdDatabasePruner
    {
        public function __construct(private readonly Connection $databaseConnection) {}

        public function supported(): bool
        {
            return true;
        }

        protected function connection(string $name): Connection
        {
            return $this->databaseConnection;
        }
    };

    config([
        'database.default' => 'mysql',
        'database.connections.mysql.driver' => 'mysql',
    ]);

    expect(fn () => $pruner->drop(['active_database']))
        ->toThrow(RuntimeException::class, 'Refusing to drop the active database [active_database].');
});
