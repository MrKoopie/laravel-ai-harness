<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Environment;

use JsonException;

/** Track schema privileges added by the harness without taking ownership of existing grants. */
final class ExistingMySqlGrants
{
    private const TABLE = '`ai_harness_registry`.`schema_grants`';

    /** @var array<string, string> Database-level flags shared by MySQL and MariaDB. */
    private const PRIVILEGES = [
        'Select_priv' => 'SELECT', 'Insert_priv' => 'INSERT', 'Update_priv' => 'UPDATE',
        'Delete_priv' => 'DELETE', 'Create_priv' => 'CREATE', 'Drop_priv' => 'DROP',
        'References_priv' => 'REFERENCES', 'Index_priv' => 'INDEX', 'Alter_priv' => 'ALTER',
        'Create_tmp_table_priv' => 'CREATE TEMPORARY TABLES', 'Lock_tables_priv' => 'LOCK TABLES',
        'Execute_priv' => 'EXECUTE', 'Create_view_priv' => 'CREATE VIEW', 'Show_view_priv' => 'SHOW VIEW',
        'Create_routine_priv' => 'CREATE ROUTINE', 'Alter_routine_priv' => 'ALTER ROUTINE',
        'Event_priv' => 'EVENT', 'Trigger_priv' => 'TRIGGER',
    ];

    private ?bool $registryExists = null;

    /** Use the same locked provisioning session for grants and ownership metadata. */
    public function __construct(private readonly DatabaseConnection $db) {}

    /** Prepare grant ownership metadata outside the application schemas. */
    public function ensureRegistry(): void
    {
        $this->db->execute('CREATE TABLE IF NOT EXISTS '.self::TABLE.' (id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, database_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, owner CHAR(64) NOT NULL, token CHAR(64) NOT NULL, username VARCHAR(128) NOT NULL, user_host VARCHAR(255) NOT NULL, grant_schema VARCHAR(128) CHARACTER SET ascii NOT NULL, baseline TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
        $this->registryExists = true;
    }

    /** Save the original exact-schema privileges before granting the application's required access. */
    public function grant(string $name, string $owner, string $token, string $username, string $host, string $schema): void
    {
        $id = hash('sha256', $name."\0".$username."\0".$host);
        $rows = $this->db->rows('SELECT * FROM '.self::TABLE.' WHERE id = ?', [$id]);
        if ($rows === []) {
            $baseline = json_encode($this->privileges($username, $host, $schema), JSON_THROW_ON_ERROR);
            $this->db->execute('INSERT INTO '.self::TABLE.' (id, database_name, owner, token, username, user_host, grant_schema, baseline) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$id, $name, $owner, $token, $username, $host, $schema, $baseline]);
        } else {
            $this->validate($rows[0], $name, $owner, $token);
            if ($rows[0]['grant_schema'] !== $schema) {
                throw new EnvironmentException('Database grant mode changed; restore the original server privilege mode before retrying.');
            }
        }
        $this->db->execute('GRANT '.implode(', ', self::PRIVILEGES).' ON `'.$schema.'`.* TO ?@?', [$username, $host]);
    }

    /** Revoke only additional exact-schema privileges, retaining evidence until the schema is dropped. */
    public function revoke(string $name, string $owner, string $token): void
    {
        if (! $this->hasRegistry()) {
            // Older ownership records have no privilege baseline; never assume those grants are ours.
            return;
        }
        $rows = $this->db->rows('SELECT * FROM '.self::TABLE.' WHERE database_name = ?', [$name]);
        foreach ($rows as $row) {
            $baseline = $this->validate($row, $name, $owner, $token);
            $username = $row['username'];
            $host = $row['user_host'];
            $schema = $row['grant_schema'];
            if (! is_string($username) || ! is_string($host) || ! is_string($schema)) {
                throw new EnvironmentException('Invalid recorded database grant account.');
            }
            $partial = $this->db->rows("SHOW VARIABLES LIKE 'partial_revokes'");
            $literal = strtoupper((string) ($partial[0]['Value'] ?? 'OFF')) === 'ON';
            if ($schema !== ($literal ? $name : str_replace('_', '\\_', $name))) {
                throw new EnvironmentException('Database grant mode changed; restore the original server privilege mode before retrying.');
            }
            $added = array_values(array_diff($this->privileges($username, $host, $schema), $baseline));
            if ($added !== []) {
                $this->db->execute('REVOKE '.implode(', ', $added).' ON `'.$schema.'`.* FROM ?@?', [$username, $host]);
            }
        }
    }

    /** Remove grant metadata only after both revocation and schema teardown succeeded. */
    public function forget(string $name, string $owner, string $token): void
    {
        if ($this->hasRegistry()) {
            $this->db->execute('DELETE FROM '.self::TABLE.' WHERE database_name = ? AND owner = ? AND token = ?', [$name, $owner, $token]);
        }
    }

    /** Read only exact-schema grants; broader pre-existing permissions are never changed.
     * @return list<string>
     */
    private function privileges(string $username, string $host, string $schema): array
    {
        $rows = $this->db->rows('SELECT * FROM mysql.db WHERE User = ? AND Host = ? AND Db = ?', [$username, $host, $schema]);
        $privileges = [];
        foreach (self::PRIVILEGES as $flag => $privilege) {
            if (($rows[0][$flag] ?? 'N') === 'Y') {
                $privileges[] = $privilege;
            }
        }

        return $privileges;
    }

    /** Validate ownership and the baseline before using any recorded grant identifiers.
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function validate(array $row, string $name, string $owner, string $token): array
    {
        if (($row['database_name'] ?? null) !== $name || ($row['owner'] ?? null) !== $owner || ($row['token'] ?? null) !== $token
            || ! is_string($row['username'] ?? null) || ! is_string($row['user_host'] ?? null)
            || ($row['id'] ?? null) !== hash('sha256', $name."\0".$row['username']."\0".$row['user_host'])
            || ! in_array($row['grant_schema'] ?? null, [$name, str_replace('_', '\\_', $name)], true)
            || ! is_string($row['baseline'] ?? null)) {
            throw new EnvironmentException('Database grant ownership does not match this checkout.');
        }
        try {
            $baseline = json_decode($row['baseline'], true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new EnvironmentException('Invalid recorded database grant baseline.');
        }
        if (! is_array($baseline) || ! array_is_list($baseline)) {
            throw new EnvironmentException('Invalid recorded database grant baseline.');
        }
        $result = [];
        foreach ($baseline as $privilege) {
            if (! is_string($privilege) || ! in_array($privilege, self::PRIVILEGES, true)) {
                throw new EnvironmentException('Invalid recorded database grant privilege.');
            }
            $result[] = $privilege;
        }

        return $result;
    }

    /** Allow safe cleanup of schemas recorded before grant ownership tracking was introduced. */
    private function hasRegistry(): bool
    {
        return $this->registryExists ??= $this->db->rows("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'ai_harness_registry' AND TABLE_NAME = 'schema_grants'") !== [];
    }
}
