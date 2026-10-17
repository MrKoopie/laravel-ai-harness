<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Environment;

use Closure;
use MrKoopie\LaravelAiHarness\Config\ExistingDatabase;
use Throwable;

/** Owns schemas, never the server process or application account. */
final readonly class ExistingMySql
{
    private const REGISTRY = '`ai_harness_registry`.`databases`';

    /** Create the schema owner coordinator with an optional test connection factory.
     * @param  null|Closure(ExistingDatabase, bool): DatabaseConnection  $connect
     */
    public function __construct(private StateStore $state, private ?Closure $connect = null) {}

    /** Create or recover only schemas belonging to the current checkout. */
    public function setup(string $root, ExistingDatabase $config): void
    {
        $this->manage($root, $config, false);
    }

    /** Revoke owned grants and remove individually verified checkout schemas. */
    public function cleanup(string $root, ExistingDatabase $config): void
    {
        $this->manage($root, $config, true);
    }

    /** Return read-only diagnostics; never expose driver errors containing SQL or credentials.
     * @return array{bool, string}
     */
    public function diagnose(ExistingDatabase $config): array
    {
        try {
            $db = $this->connection($config);
            $db->rows('SELECT 1');
            $grants = $db->rows('SHOW GRANTS');
            $global = [];
            $grantOption = false;
            foreach ($grants as $grant) {
                $text = strtoupper(implode(' ', $grant));
                if (preg_match('/^GRANT (.+) ON \*\.\* TO .+/', $text, $match) === 1) {
                    $global = [...$global, ...array_map('trim', explode(',', $match[1]))];
                    $grantOption = $grantOption || preg_match('/ WITH GRANT OPTION$/', $text) === 1;
                }
            }
            $missing = [];
            // Every schema privilege we grant must be available, along with registry metadata access.
            foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'REFERENCES', 'INDEX', 'ALTER', 'CREATE TEMPORARY TABLES', 'LOCK TABLES', 'EXECUTE', 'CREATE VIEW', 'SHOW VIEW', 'CREATE ROUTINE', 'ALTER ROUTINE', 'EVENT', 'TRIGGER', 'GRANT OPTION'] as $privilege) {
                $all = in_array('ALL PRIVILEGES', $global, true) && $privilege !== 'GRANT OPTION';
                $present = $privilege === 'GRANT OPTION' ? $grantOption : in_array($privilege, $global, true);
                if (! $all && ! $present) {
                    $missing[] = $privilege;
                }
            }
            $db->rows('SHOW GRANTS FOR ?@?', [$config->username, $this->applicationHost($config)]);
            $this->connection($config, true)->rows('SELECT 1');

            return [$missing === [], $missing === []
                ? 'Existing-server MySQL/MariaDB application and provisioning connections succeeded; global grants found'
                : 'Existing-server MySQL/MariaDB connected; missing or unverified provisioning privileges: '.implode(', ', $missing).' (role-derived grants require manual review)'];
        } catch (Throwable) {
            return [false, 'Existing-server MySQL/MariaDB connection or privilege inspection failed; check credentials, endpoint and pdo_mysql'];
        }
    }

    /** Connect without selecting an application database. */
    private function connection(ExistingDatabase $config, bool $application = false): DatabaseConnection
    {
        return $this->connect !== null ? ($this->connect)($config, $application) : new PdoDatabaseConnection($config, $application);
    }

    /** Resolve the authenticated MySQL account host, e.g. localhost or %. */
    private function applicationHost(ExistingDatabase $config): string
    {
        if ($config->userHost !== '') {
            return $config->userHost;
        }
        $rows = $this->connection($config, true)->rows('SELECT CURRENT_USER() AS account');
        $account = $rows[0]['account'] ?? null;
        $separator = is_string($account) ? strrpos($account, '@') : false;
        if ($separator === false || substr($account, 0, $separator) !== $config->username) {
            throw new EnvironmentException('Unable to verify the authenticated application database account.');
        }

        return substr($account, $separator + 1);
    }

    /** Enumerate the only development, testing and parallel schema names this checkout can own.
     * @return list<string>
     */
    private function names(string $root, ExistingDatabase $config): array
    {
        $names = [$config->developmentName($root), $config->testingName($root)];
        for ($i = 1; $i <= 64; $i++) {
            $names[] = $config->testingName($root).'_test_'.$i;
        }

        return $names;
    }

    /** Verify local ownership and normalize legacy endpoint fingerprints before any server mutation.
     * @return array{owner: string, endpoint: string, token: string, names: list<string>}|null
     */
    private function ownership(string $root, ExistingDatabase $config): ?array
    {
        $record = $this->state->existingDatabases($root);
        if ($record === null) {
            return null;
        }
        if (($record['owner'] ?? null) !== hash('sha256', $root) || ! in_array($record['endpoint'] ?? null, [$config->endpoint(), $config->legacyEndpoint()], true)
            || ! is_string($record['token'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $record['token']) !== 1
            || ! is_array($record['names'] ?? null) || ! array_is_list($record['names'])) {
            throw new EnvironmentException('Existing-server database ownership does not match this checkout or endpoint; restore its original configuration.');
        }
        $names = [];
        foreach ($record['names'] as $name) {
            if (! is_string($name) || ! in_array($name, $this->names($root, $config), true)) {
                throw new EnvironmentException('Existing-server database ownership contains an unexpected database name.');
            }
            $names[] = $name;
        }

        return ['owner' => hash('sha256', $root), 'endpoint' => $config->endpoint(), 'token' => $record['token'], 'names' => $names];
    }

    /** Serialize provisioning and teardown around shared server ownership records. */
    private function manage(string $root, ExistingDatabase $config, bool $cleanup): void
    {
        $record = $this->ownership($root, $config);
        if ($cleanup && $record === null) {
            return;
        }
        $owner = hash('sha256', $root);
        $allowed = $this->names($root, $config);
        try {
            $userHost = $cleanup ? '' : $this->applicationHost($config);
            $db = $this->connection($config);
            $grants = new ExistingMySqlGrants($db);
            // One server-wide lock also serializes registry initialization and retries across checkouts.
            $lock = $db->rows("SELECT GET_LOCK('ai_harness_external_databases', 10) AS acquired");
            if ((string) ($lock[0]['acquired'] ?? '') !== '1') {
                throw new EnvironmentException('Existing-server database provisioning is busy; retry later.');
            }
            try {
                $record = $this->ownership($root, $config);
                if (! $cleanup) {
                    // MariaDB may implicitly create accounts on GRANT; explicitly require an existing account.
                    $db->rows('SHOW GRANTS FOR ?@?', [$config->username, $userHost]);
                    $names = array_slice($allowed, 0, 2 + $config->parallelProcesses);
                    $record ??= ['owner' => $owner, 'endpoint' => $config->endpoint(), 'token' => bin2hex(random_bytes(32)), 'names' => []];
                    $record['database'] = $config->developmentName($root);
                    $record['names'] = array_values(array_unique([...$record['names'], ...$names]));
                    $this->state->recordExistingDatabases($root, $record);
                    $db->execute('CREATE DATABASE IF NOT EXISTS `ai_harness_registry`');
                    $db->execute('CREATE TABLE IF NOT EXISTS '.self::REGISTRY.' (name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, owner CHAR(64) NOT NULL, token CHAR(64) NOT NULL, created TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB');
                    $grants->ensureRegistry();
                }
                if ($record === null) {
                    return;
                }
                foreach ($record['names'] as $name) {
                    $created = false;
                    $rows = $db->rows('SELECT owner, token, created FROM '.self::REGISTRY.' WHERE name = ?', [$name]);
                    $exists = $db->rows('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$name]) !== [];
                    if ($rows === []) {
                        if ($exists) {
                            throw new EnvironmentException('Refusing an existing database without matching ownership evidence.');
                        }
                        if ($cleanup) {
                            continue;
                        }
                        // Write-ahead reservation: a failed CREATE can be retried without adopting an existing schema.
                        $db->execute('INSERT INTO '.self::REGISTRY.' (name, owner, token) VALUES (?, ?, ?)', [$name, $owner, $record['token']]);
                    } else {
                        if (($rows[0]['owner'] ?? null) !== $owner || ($rows[0]['token'] ?? null) !== $record['token']) {
                            throw new EnvironmentException('Existing-server database registry ownership does not match this checkout.');
                        }
                        $created = (string) ($rows[0]['created'] ?? '') === '1';
                    }
                    if ($exists && ! $created) {
                        throw new EnvironmentException('Existing-server database creation was interrupted or collided with another creator; existing schema ownership is unverified. Preserve the records and ask the database administrator to verify it.');
                    }
                    if ($cleanup) {
                        $grants->revoke($name, $owner, $record['token']);
                        if ($created) {
                            $db->execute('DROP DATABASE IF EXISTS `'.$name.'`');
                        }
                        $grants->forget($name, $owner, $record['token']);
                        $db->execute('DELETE FROM '.self::REGISTRY.' WHERE name = ? AND owner = ? AND token = ?', [$name, $owner, $record['token']]);
                    } else {
                        if (! $exists) {
                            // No IF NOT EXISTS: a concurrent unrelated creator must never be adopted.
                            $db->execute('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                        }
                        if (! $created) {
                            $db->execute('UPDATE '.self::REGISTRY.' SET created = 1 WHERE name = ? AND owner = ? AND token = ?', [$name, $owner, $record['token']]);
                        }
                        $partial = $db->rows("SHOW VARIABLES LIKE 'partial_revokes'");
                        $literalGrants = strtoupper((string) ($partial[0]['Value'] ?? 'OFF')) === 'ON';
                        $grantName = $literalGrants ? $name : str_replace('_', '\\_', $name);
                        // Grant only exact schemas, including pre-provisioned Laravel parallel test tokens.
                        $grants->grant($name, $owner, $record['token'], $config->username, $userHost, $grantName);
                    }
                }
                if ($cleanup) {
                    $this->state->clearExistingDatabases($root);
                }
            } finally {
                try {
                    $db->rows("SELECT RELEASE_LOCK('ai_harness_external_databases')");
                } catch (Throwable) {
                    // Closing this session also releases its lock; retain the original operation result.
                }
            }
        } catch (EnvironmentException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new EnvironmentException('Existing-server MySQL/MariaDB operation failed; check connectivity, account existence and provisioning privileges. Ownership records were retained; retry the command.');
        }
    }
}
