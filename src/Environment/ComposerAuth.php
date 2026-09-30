<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Environment;

/**
 * Validate the COMPOSER_AUTH environment variable without exposing its credentials.
 *
 * Composer reads COMPOSER_AUTH natively and merges it with auth.json. The harness only
 * checks the structure, so that a malformed value fails early in doctor. Messages name
 * authentication types and hosts, but never usernames, tokens, passwords, or headers.
 */
final readonly class ComposerAuth
{
    public const VARIABLE = 'COMPOSER_AUTH';

    /** Authentication types whose values map a host to one token string. */
    private const TOKEN_TYPES = ['bearer', 'github-oauth'];

    /** Authentication types whose values map a host to an object with required string fields. */
    private const OBJECT_TYPES = [
        'http-basic' => ['username', 'password'],
        'bitbucket-oauth' => ['consumer-key', 'consumer-secret'],
        'forgejo-token' => ['username', 'token'],
        'client-certificate' => ['local_cert'],
    ];

    /** Authentication types whose values are a token string or an object with required string fields. */
    private const TOKEN_OR_OBJECT_TYPES = [
        'gitlab-oauth' => ['token'],
        'gitlab-token' => ['username', 'token'],
    ];

    /** Composer settings that often go together with authentication and hold only host names. */
    private const DOMAIN_LISTS = ['github-domains', 'gitlab-domains', 'forgejo-domains'];

    /**
     * @param  list<string>  $errors
     * @param  array<string, list<string>>  $hosts
     */
    private function __construct(
        public array $errors,
        public array $hosts,
    ) {}

    /** Read COMPOSER_AUTH from the process environment, or return null when it is not set. */
    public static function fromEnvironment(): ?self
    {
        $value = getenv(self::VARIABLE);

        // Composer ignores an empty value, so the harness does the same.
        if (! is_string($value) || $value === '') {
            return null;
        }

        return self::inspect($value);
    }

    /** Validate one COMPOSER_AUTH value. */
    public static function inspect(string $value): self
    {
        try {
            $data = json_decode($value, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new self([self::VARIABLE.' is not valid JSON'], []);
        }

        if (! $data instanceof \stdClass) {
            return new self([self::VARIABLE.' must be a JSON object'], []);
        }

        $errors = [];
        $hosts = [];

        foreach (get_object_vars($data) as $type => $entries) {
            $type = (string) $type;

            if (in_array($type, self::DOMAIN_LISTS, true)) {
                if (! self::isStringList($entries)) {
                    $errors[] = "{$type} must be a list of host names";
                }

                continue;
            }

            if (! self::isKnownType($type)) {
                // A misplaced secret can end up as a key, so only a key that looks like a type name is shown.
                $errors[] = preg_match('/^[a-z-]{1,40}$/', $type) === 1
                    ? "{$type} is not a known Composer authentication type"
                    : 'An unknown key is not a Composer authentication type';

                continue;
            }

            if (! $entries instanceof \stdClass) {
                $errors[] = "{$type} must be an object of host names";

                continue;
            }

            foreach (get_object_vars($entries) as $host => $credentials) {
                $host = self::displayHost((string) $host);
                $error = self::credentialError($type, $credentials);

                if ($error !== null) {
                    $errors[] = "{$type} for {$host} {$error}";

                    continue;
                }

                $hosts[$type][] = $host;
            }
        }

        if ($errors === [] && $hosts === []) {
            $errors[] = self::VARIABLE.' contains no credentials';
        }

        return new self($errors, $hosts);
    }

    /** Determine whether the value can be used by Composer. */
    public function valid(): bool
    {
        return $this->errors === [];
    }

    /** Describe the configured authentication types and hosts without credentials. */
    public function summary(): string
    {
        $parts = [];

        foreach ($this->hosts as $type => $hosts) {
            $parts[] = $type.' ('.implode(', ', $hosts).')';
        }

        return implode('; ', $parts);
    }

    /** Find a compose file that forwards COMPOSER_AUTH to the Sail application service, or return null. */
    public static function composeFileForwarding(string $root): ?string
    {
        $service = self::sailService($root);

        foreach (self::composeFiles($root) as $file) {
            $contents = is_file($file) && ! is_link($file) ? file_get_contents($file) : false;

            if (is_string($contents) && self::serviceForwards($contents, $service)) {
                return $file;
            }
        }

        return null;
    }

    /** Return the Sail application service: APP_SERVICE from the process or .env, else laravel.test. */
    public static function sailService(string $root): string
    {
        $service = getenv('APP_SERVICE');

        // Sail reads .env before it runs Docker Compose, so APP_SERVICE can also come from there.
        if ((! is_string($service) || $service === '') && is_file($root.'/.env') && ! is_link($root.'/.env')) {
            $environment = (string) file_get_contents($root.'/.env');

            if (preg_match('/^[ \t]*(?:export[ \t]+)?APP_SERVICE[ \t]*=[ \t]*["\']?([A-Za-z0-9._-]+)["\']?[ \t]*$/m', $environment, $matches) === 1) {
                $service = $matches[1];
            }
        }

        return is_string($service) && $service !== '' ? $service : 'laravel.test';
    }

    /**
     * Determine whether one compose file forwards COMPOSER_AUTH in the environment of one service.
     *
     * This is a small indentation-based reader for the common block style, so that the harness
     * does not need a YAML dependency. It accepts the map form (COMPOSER_AUTH: ...), the list
     * form (- COMPOSER_AUTH or - COMPOSER_AUTH=...), and an inline flow list or map.
     */
    private static function serviceForwards(string $contents, string $service): bool
    {
        $lines = preg_split('/\R/', $contents) ?: [];
        $entry = '/^(?:-[ \t]*)?["\']?COMPOSER_AUTH["\']?[ \t]*(?::|=|$)/';
        $path = [];

        foreach ($lines as $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            $indent = strlen($line) - strlen(ltrim($line, " \t"));
            $text = trim($line);

            // Leave the blocks that this line closes. A list item may use the indent of its parent key.
            $depth = str_starts_with($text, '-') ? $indent + 1 : $indent;

            while ($path !== [] && $depth <= $path[array_key_last($path)][0]) {
                array_pop($path);
            }

            $keys = array_map(static fn (array $level): string => $level[1], $path);

            if ($keys === ['services', $service, 'environment'] && preg_match($entry, $text) === 1) {
                return true;
            }

            if (preg_match('/^["\']?([^"\':#]+?)["\']?[ \t]*:(?:[ \t]+(.*))?$/', $text, $matches) !== 1) {
                continue;
            }

            $key = $matches[1];
            $value = trim($matches[2] ?? '');

            // A value that is only a comment, for example environment: # note, opens a block.
            if (str_starts_with($value, '#')) {
                $value = '';
            }

            if ($keys === ['services', $service] && $key === 'environment' && $value !== '') {
                // Inline flow style, for example environment: [COMPOSER_AUTH] or {COMPOSER_AUTH: ...}.
                return preg_match('/[\[{,][ \t]*["\']?COMPOSER_AUTH["\']?[ \t]*(?:[:=,\]}]|$)/', $value) === 1;
            }

            if ($value === '') {
                $path[] = [$indent, $key];
            }
        }

        return false;
    }

    /**
     * List the compose files that Sail uses: SAIL_FILES, or the Docker Compose default and override files.
     *
     * @return list<string>
     */
    public static function composeFiles(string $root): array
    {
        $configured = getenv('SAIL_FILES');

        if (is_string($configured) && $configured !== '') {
            return array_values(array_map(
                static fn (string $file): string => str_starts_with($file, '/') ? $file : $root.'/'.$file,
                array_filter(explode(':', $configured), static fn (string $file): bool => $file !== ''),
            ));
        }

        // Without -f, Docker Compose loads the first default file and the first default override file.
        $base = self::firstFile($root, ['compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml']);

        if ($base === null) {
            return [];
        }

        $override = self::firstFile($root, ['compose.override.yaml', 'compose.override.yml', 'docker-compose.override.yaml', 'docker-compose.override.yml']);

        return $override === null ? [$base] : [$base, $override];
    }

    /**
     * Return the path of the first existing file, or null.
     *
     * @param  list<string>  $names
     */
    private static function firstFile(string $root, array $names): ?string
    {
        foreach ($names as $name) {
            if (is_file($root.'/'.$name)) {
                return $root.'/'.$name;
            }
        }

        return null;
    }

    /** Show a host name, but hide a key that does not look like one, because it can be a misplaced secret. */
    private static function displayHost(string $host): string
    {
        $pattern = '/^(?:localhost|(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?::[0-9]{1,5})?$/';

        if (strlen($host) > 253 || preg_match($pattern, $host) !== 1) {
            return '[hidden host]';
        }

        // A long label that mixes letters and digits looks like a token, not a host name.
        foreach (explode('.', explode(':', $host)[0]) as $label) {
            if (strlen($label) >= 16 && preg_match('/[A-Za-z]/', $label) === 1 && preg_match('/[0-9]/', $label) === 1) {
                return '[hidden host]';
            }
        }

        return $host;
    }

    /** Determine whether a key is an authentication type that Composer supports. */
    private static function isKnownType(string $type): bool
    {
        return in_array($type, self::TOKEN_TYPES, true)
            || $type === 'custom-headers'
            || array_key_exists($type, self::OBJECT_TYPES)
            || array_key_exists($type, self::TOKEN_OR_OBJECT_TYPES);
    }

    /** Return a problem with one host entry, without its values, or null when it is valid. */
    private static function credentialError(string $type, mixed $credentials): ?string
    {
        if (in_array($type, self::TOKEN_TYPES, true)) {
            return self::isFilledString($credentials) ? null : 'must be a token string';
        }

        if ($type === 'custom-headers') {
            if (! is_array($credentials) || $credentials === [] || ! array_is_list($credentials)) {
                return 'must be a list of headers';
            }

            foreach ($credentials as $header) {
                if (! is_string($header) || preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+:/', $header) !== 1) {
                    return 'must contain only "Name: value" headers';
                }
            }

            return null;
        }

        if (array_key_exists($type, self::TOKEN_OR_OBJECT_TYPES) && self::isFilledString($credentials)) {
            return null;
        }

        $fields = self::OBJECT_TYPES[$type] ?? self::TOKEN_OR_OBJECT_TYPES[$type];

        if (! $credentials instanceof \stdClass) {
            return array_key_exists($type, self::TOKEN_OR_OBJECT_TYPES)
                ? 'must be a token string or an object with '.implode(' and ', $fields)
                : 'must be an object with '.implode(' and ', $fields);
        }

        foreach ($fields as $field) {
            if (! self::isFilledString($credentials->{$field} ?? null)) {
                return "requires a non-empty {$field}";
            }
        }

        return null;
    }

    /** Determine whether a value is a non-empty string. */
    private static function isFilledString(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }

    /** Determine whether a value is a list of non-empty strings. */
    private static function isStringList(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! self::isFilledString($item)) {
                return false;
            }
        }

        return true;
    }
}
