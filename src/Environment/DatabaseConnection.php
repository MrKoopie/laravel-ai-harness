<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Environment;

interface DatabaseConnection
{
    /** Execute a parameterized server operation.
     * @param  list<string>  $parameters
     */
    public function execute(string $sql, array $parameters = []): void;

    /** Fetch server metadata without logging values.
     * @param  list<string>  $parameters
     * @return list<array<string, mixed>>
     */
    public function rows(string $sql, array $parameters = []): array;
}
