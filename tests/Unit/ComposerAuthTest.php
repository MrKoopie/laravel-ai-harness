<?php

declare(strict_types=1);

use MrKoopie\LaravelAiHarness\Environment\ComposerAuth;

test('COMPOSER_AUTH accepts several hosts and authentication types', function (): void {
    $auth = ComposerAuth::inspect(json_encode([
        'http-basic' => [
            'repo.example.com' => ['username' => 'user', 'password' => 'basic-secret'],
            'satis.example.org:8443' => ['username' => 'other', 'password' => 'second-secret'],
        ],
        'github-oauth' => ['github.com' => 'ghp_githubsecret'],
        'gitlab-token' => [
            'gitlab.com' => 'glpat-plainsecret',
            'gitlab.example.com' => ['username' => 'deploy', 'token' => 'glpat-objectsecret'],
        ],
        'gitlab-oauth' => ['gitlab.example.net' => ['token' => 'oauth-secret', 'expires-at' => 1]],
        'bitbucket-oauth' => ['bitbucket.org' => ['consumer-key' => 'key-secret', 'consumer-secret' => 'consumer-secret']],
        'bearer' => ['packages.example.com' => 'bearer-secret'],
        'forgejo-token' => ['codeberg.org' => ['username' => 'user', 'token' => 'forgejo-secret']],
        'custom-headers' => ['private.example.com' => ['X-Api-Key: header-secret']],
        'client-certificate' => ['mtls.example.com' => ['local_cert' => '/certs/client.pem']],
        'gitlab-domains' => ['gitlab.example.com'],
        'github-domains' => ['github.com', 'github.example.com'],
        'forgejo-domains' => ['codeberg.org'],
    ], JSON_THROW_ON_ERROR));

    expect($auth->valid())->toBeTrue()
        ->and($auth->hosts['http-basic'])->toBe(['repo.example.com', 'satis.example.org:8443'])
        ->and($auth->hosts['gitlab-token'])->toBe(['gitlab.com', 'gitlab.example.com'])
        ->and($auth->summary())->toContain('http-basic (repo.example.com, satis.example.org:8443)', 'bearer (packages.example.com)')
        ->and($auth->summary())->not->toContain('secret', 'deploy', '/certs');
});

test('COMPOSER_AUTH errors name the problem but never a credential', function (): void {
    foreach ([
        'invalid JSON' => ['{"http-basic": s3cr3t', 'COMPOSER_AUTH is not valid JSON'],
        'not an object' => ['["s3cr3t"]', 'COMPOSER_AUTH must be a JSON object'],
        'empty object' => ['{}', 'COMPOSER_AUTH contains no credentials'],
        'missing password' => ['{"http-basic":{"repo.example.com":{"username":"s3cr3t"}}}', 'http-basic for repo.example.com requires a non-empty password'],
        'token object' => ['{"github-oauth":{"github.com":{"token":"s3cr3t"}}}', 'github-oauth for github.com must be a token string'],
        'type not an object' => ['{"bearer":"s3cr3t"}', 'bearer must be an object of host names'],
        'misspelled type' => ['{"http_basic":{"repo.example.com":{"username":"u","password":"s3cr3t"}}}', 'An unknown key is not a Composer authentication type'],
        'unknown type' => ['{"github-token":{"github.com":"s3cr3t"}}', 'github-token is not a known Composer authentication type'],
        'secret as a host' => ['{"github-oauth":{"ghp_s3cr3t":{}}}', 'github-oauth for [hidden host] must be a token string'],
        'header without a name' => ['{"custom-headers":{"api.example.com":["s3cr3t"]}}', 'must contain only "Name: value" headers'],
        'gitlab token object' => ['{"gitlab-token":{"gitlab.com":{"token":"s3cr3t"}}}', 'gitlab-token for gitlab.com requires a non-empty username'],
        'domain list' => ['{"gitlab-domains":"gitlab.example.com"}', 'gitlab-domains must be a list of host names'],
    ] as [$value, $error]) {
        $auth = ComposerAuth::inspect($value);

        expect($auth->valid())->toBeFalse()
            ->and(implode("\n", $auth->errors))->toContain($error)
            ->and(implode("\n", $auth->errors))->not->toContain('s3cr3t');
    }
});

test('a secret that looks like a type name is not shown in errors', function (): void {
    // Only lowercase letters and dashes are shown, so a typical token is hidden.
    $auth = ComposerAuth::inspect('{"ghp_S3cr3tToken123":{"github.com":"x"}}');

    expect($auth->errors)->toBe(['An unknown key is not a Composer authentication type']);
});

test('an empty COMPOSER_AUTH is ignored as Composer does', function (): void {
    $previous = getenv('COMPOSER_AUTH');
    putenv('COMPOSER_AUTH=');

    try {
        expect(ComposerAuth::fromEnvironment())->toBeNull();

        putenv('COMPOSER_AUTH={"bearer":{"example.com":"token"}}');

        expect(ComposerAuth::fromEnvironment()?->valid())->toBeTrue();
    } finally {
        putenv($previous === false ? 'COMPOSER_AUTH' : 'COMPOSER_AUTH='.$previous);
    }
});

test('the Sail compose file forwards COMPOSER_AUTH in map or list form', function (): void {
    $previous = getenv('SAIL_FILES');
    putenv('SAIL_FILES');

    try {
        $root = temp_directory('harness-composer-auth');

        expect(ComposerAuth::composeFileForwarding($root))->toBeNull();

        file_put_contents($root.'/compose.yaml', "services:\n  laravel.test:\n    environment:\n      WWWUSER: '\${WWWUSER}'\n");
        expect(ComposerAuth::composeFileForwarding($root))->toBeNull();

        file_put_contents($root.'/compose.yaml', "services:\n  laravel.test:\n    environment:\n      COMPOSER_AUTH: '\${COMPOSER_AUTH:-}'\n");
        expect(ComposerAuth::composeFileForwarding($root))->toBe($root.'/compose.yaml');

        file_put_contents($root.'/compose.yaml', "services:\n  laravel.test:\n    environment:\n      - COMPOSER_AUTH\n");
        expect(ComposerAuth::composeFileForwarding($root))->toBe($root.'/compose.yaml');

        file_put_contents($root.'/compose.yaml', "services:\n  laravel.test:\n    environment:\n      # COMPOSER_AUTH is not forwarded\n");
        expect(ComposerAuth::composeFileForwarding($root))->toBeNull();

        file_put_contents($root.'/compose.override.yaml', "services:\n  laravel.test:\n    environment:\n      - COMPOSER_AUTH\n");
        file_put_contents($root.'/compose.yaml', "services:\n  laravel.test:\n    image: example\n");
        expect(ComposerAuth::composeFiles($root))->toBe([$root.'/compose.yaml', $root.'/compose.override.yaml'])
            ->and(ComposerAuth::composeFileForwarding($root))->toBe($root.'/compose.override.yaml');
        unlink($root.'/compose.override.yaml');

        file_put_contents($root.'/docker-compose.override.yml', "services:\n  laravel.test:\n    environment:\n      - COMPOSER_AUTH=\${COMPOSER_AUTH}\n");
        putenv('SAIL_FILES=compose.yaml:docker-compose.override.yml');
        expect(ComposerAuth::composeFileForwarding($root))->toBe($root.'/docker-compose.override.yml');
    } finally {
        putenv($previous === false ? 'SAIL_FILES' : 'SAIL_FILES='.$previous);
    }
});

test('a host label that looks like a token is hidden in the summary', function (): void {
    $auth = ComposerAuth::inspect('{"bearer":{"a1b2c3d4e5f6g7h8i9.example.com":"x","repo2.example.com":"y"}}');

    expect($auth->valid())->toBeTrue()
        ->and($auth->summary())->toBe('bearer ([hidden host], repo2.example.com)');
});

test('only the environment of the Sail application service counts as forwarding', function (): void {
    $previous = ['SAIL_FILES' => getenv('SAIL_FILES'), 'APP_SERVICE' => getenv('APP_SERVICE')];
    putenv('SAIL_FILES');
    putenv('APP_SERVICE');

    try {
        $root = temp_directory('harness-composer-auth-service');

        $cases = [
            'other service' => ["services:\n  laravel.test:\n    image: app\n    environment:\n      WWWUSER: '1000'\n  worker:\n    environment:\n      COMPOSER_AUTH: '\${COMPOSER_AUTH:-}'\n", false],
            'build argument' => ["services:\n  laravel.test:\n    build:\n      args:\n        COMPOSER_AUTH: x\n", false],
            'compact list' => ["services:\n  laravel.test:\n    environment:\n    - WWWUSER\n    - COMPOSER_AUTH\n  mysql:\n    image: mysql\n", true],
            'inline list' => ["services:\n  'laravel.test':\n    environment: [WWWUSER, COMPOSER_AUTH]\n", true],
            'inline map' => ["services:\n  laravel.test:\n    environment: {COMPOSER_AUTH: '\${COMPOSER_AUTH:-}'}\n", true],
            'trailing comments' => ["services: # all services\n  laravel.test: # app\n    environment: # Composer credentials\n      COMPOSER_AUTH: '\${COMPOSER_AUTH:-}'\n", true],
            'inline list with comment' => ["services:\n  laravel.test:\n    environment: [COMPOSER_AUTH] # forwarded\n", true],
            'after other keys' => ["services:\n  mysql:\n    environment:\n      MYSQL_ROOT_PASSWORD: x\n  laravel.test:\n    ports:\n      - '\${APP_PORT:-80}:80'\n    environment:\n      # comment\n      COMPOSER_AUTH: '\${COMPOSER_AUTH:-}'\n", true],
        ];

        foreach ($cases as $name => [$yaml, $forwarded]) {
            file_put_contents($root.'/compose.yaml', $yaml);

            expect(ComposerAuth::composeFileForwarding($root) !== null)->toBe($forwarded, $name);
        }

        file_put_contents($root.'/compose.yaml', "services:\n  app:\n    environment:\n      - COMPOSER_AUTH\n");
        expect(ComposerAuth::composeFileForwarding($root))->toBeNull();

        file_put_contents($root.'/.env', "APP_SERVICE=app\n");
        expect(ComposerAuth::sailService($root))->toBe('app')
            ->and(ComposerAuth::composeFileForwarding($root))->toBe($root.'/compose.yaml');
    } finally {
        foreach ($previous as $name => $value) {
            putenv($value === false ? $name : $name.'='.$value);
        }
    }
});
