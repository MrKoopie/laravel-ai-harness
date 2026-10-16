<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Environment;

use MrKoopie\LaravelAiHarness\Config\ExistingDatabase;
use PDO;

final readonly class PdoDatabaseConnection implements DatabaseConnection
{
    private PDO $pdo;

    public function __construct(ExistingDatabase $config, bool $application = false)
    {
        $endpoint = $config->socket !== '' ? 'unix_socket='.$config->socket : 'host='.$config->host.';port='.$config->port;
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => true, PDO::ATTR_TIMEOUT => 10];
        if ($config->sslCa !== '') {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $config->sslCa;
        }
        $this->pdo = new PDO('mysql:'.$endpoint.';charset=utf8mb4', $application ? $config->username : $config->provisionUsername, $application ? $config->password : $config->provisionPassword, $options);
    }

    public function execute(string $sql, array $parameters = []): void
    {
        $this->pdo->prepare($sql)->execute($parameters);
    }

    public function rows(string $sql, array $parameters = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
