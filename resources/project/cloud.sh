#!/usr/bin/env bash
set -euo pipefail

profile="${AI_HARNESS_ENV:-}"

if [[ -z "$profile" && "${CLAUDE_CODE_REMOTE:-}" == true ]]; then
    profile=claude-cloud
fi

case "$profile" in
    claude-cloud|codex-cloud) ;;
    *)
        printf 'This action requires a cloud environment: set AI_HARNESS_ENV=claude-cloud or codex-cloud.\n' >&2
        exit 1
        ;;
esac

export AI_HARNESS_ENV="$profile"
project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
cd "$project_root"

case "${1:-}" in
    provision)
        if [[ "$(uname -s)" != Linux ]] || ! command -v apt-get >/dev/null 2>&1; then
            printf 'Cloud provisioning requires Ubuntu/Debian with apt-get.\n' >&2
            exit 1
        fi

        privilege=(env)

        if [[ "$(id -u)" != 0 ]]; then
            privilege=(sudo -n)
        fi

        temporary_directory="$(mktemp -d)"
        trap 'rm -rf -- "$temporary_directory"' EXIT
        apt_options=()
        apt_sources="${AI_HARNESS_APT_SOURCE_LIST:-}"
        extra_sources="${AI_HARNESS_APT_EXTRA_SOURCES:-}"
        extra_extensions="${AI_HARNESS_PHP_EXTENSIONS:-}"

        if [[ -n "$extra_extensions" && ! "$extra_extensions" =~ ^[a-z0-9]+(-[a-z0-9]+)*(,[a-z0-9]+(-[a-z0-9]+)*)*$ ]]; then
            printf 'AI_HARNESS_PHP_EXTENSIONS must be comma-separated extension names such as imagick,soap.\n' >&2
            exit 1
        fi

        if [[ -z "$apt_sources" && -f /etc/apt/sources.list.d/ubuntu.sources ]]; then
            apt_sources=/etc/apt/sources.list.d/ubuntu.sources
        elif [[ -z "$apt_sources" && -f /etc/apt/sources.list.d/debian.sources ]]; then
            apt_sources=/etc/apt/sources.list.d/debian.sources
        fi

        if [[ -n "$extra_sources" && -z "$apt_sources" ]]; then
            if [[ -f /etc/apt/sources.list ]]; then
                apt_sources=/etc/apt/sources.list
            else
                printf 'AI_HARNESS_APT_EXTRA_SOURCES requires a base source list; set AI_HARNESS_APT_SOURCE_LIST.\n' >&2
                exit 1
            fi
        fi

        if [[ -n "$apt_sources" ]]; then
            if [[ "$apt_sources" != /* || ! -f "$apt_sources" ]]; then
                printf 'AI_HARNESS_APT_SOURCE_LIST must identify an existing absolute source-list path.\n' >&2
                exit 1
            fi

            apt_options=(-o "Dir::Etc::sourcelist=$apt_sources" -o 'Dir::Etc::sourceparts=-')
        fi

        if [[ -n "$extra_sources" ]]; then
            if [[ "$extra_sources" == :* || "$extra_sources" == *: || "$extra_sources" == *::* || "$extra_sources" == *$'\n'* ]]; then
                printf 'AI_HARNESS_APT_EXTRA_SOURCES must be colon-separated absolute .list or .sources file paths without empty entries.\n' >&2
                exit 1
            fi

            IFS=':' read -r -a source_paths <<< "$extra_sources"

            for source_path in "${source_paths[@]}"; do
                if [[ "$source_path" != /* || ! -f "$source_path" || ! -r "$source_path" || ( "$source_path" != *.list && "$source_path" != *.sources ) ]]; then
                    printf 'AI_HARNESS_APT_EXTRA_SOURCES entries must be existing readable absolute .list or .sources file paths.\n' >&2
                    exit 1
                fi
            done

            source_directory="$temporary_directory/sources"
            mkdir -- "$source_directory"
            source_index=0

            for source_path in "${source_paths[@]}"; do
                cp -- "$source_path" "$source_directory/$source_index.${source_path##*.}"
                source_index=$((source_index + 1))
            done

            apt_options=(-o "Dir::Etc::sourcelist=$apt_sources" -o "Dir::Etc::sourceparts=$source_directory")
        fi

        "${privilege[@]}" apt-get "${apt_options[@]}" update
        php_version="${AI_HARNESS_PHP_VERSION:-}"

        if [[ -z "$php_version" ]]; then
            php_version="$(LC_ALL=C apt-cache "${apt_options[@]}" depends php-cli | sed -nE 's/^[[:space:]]*Depends: php([0-9]+\.[0-9]+)-cli$/\1/p' | head -n 1)"
        fi

        if [[ ! "$php_version" =~ ^[0-9]{1,2}\.[0-9]{1,2}$ ]]; then
            printf 'Set AI_HARNESS_PHP_VERSION to an available major.minor PHP version with one or two digits per component; the selected version is invalid or undetermined.\n' >&2
            exit 1
        fi

        php_major="${php_version%%.*}"
        php_minor="${php_version#*.}"

        if ((10#$php_major < 8 || (10#$php_major == 8 && 10#$php_minor < 2))); then
            printf 'Cloud setup requires PHP 8.2 or newer; select a compatible image or set AI_HARNESS_PHP_VERSION to a version available in its repositories.\n' >&2
            exit 1
        fi

        packages=(
            "php${php_version}-cli" "php${php_version}-mysql" "php${php_version}-sqlite3"
            "php${php_version}-mbstring" "php${php_version}-xml" "php${php_version}-curl"
            "php${php_version}-zip" "php${php_version}-intl" "php${php_version}-bcmath"
            "php${php_version}-redis"
            composer git unzip ca-certificates mysql-server redis-server
        )

        if [[ -n "$extra_extensions" ]]; then
            IFS=',' read -r -a extension_names <<< "$extra_extensions"

            for extension in "${extension_names[@]}"; do
                packages+=("php${php_version}-$extension")
            done
        fi

        "${privilege[@]}" env DEBIAN_FRONTEND=noninteractive apt-get "${apt_options[@]}" install -y --no-install-recommends "${packages[@]}"

        "${privilege[@]}" update-alternatives --set php "/usr/bin/php${php_version}"

        # This helper must work before vendor/ exists. Use the selected CLI,
        # not a possibly different PHP selected by a provider's PATH shim.
        php_binary="/usr/bin/php${php_version}"
        composer_json="${AI_HARNESS_COMPOSER_JSON:-$project_root/composer.json}"

        if [[ "$composer_json" != /* ]]; then
            composer_json="$project_root/$composer_json"
        fi

        requirements_directory="$temporary_directory/requirements"
        mkdir -- "$requirements_directory"

        "$php_binary" -- "$composer_json" "$requirements_directory" > "$temporary_directory/packages" <<'PHP'
<?php

// Keep parsing independent of Composer autoloading and project code.
function readObject(string $path): stdClass
{
    if (! is_file($path) || ! is_readable($path)) {
        throw new RuntimeException("Cannot read [$path]. Check AI_HARNESS_COMPOSER_JSON.");
    }

    $value = json_decode(file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);

    if (! $value instanceof stdClass) {
        throw new RuntimeException("Expected a JSON object in [$path].");
    }

    return $value;
}

function requirements(stdClass $document, string $key): array
{
    $values = $document->$key ?? new stdClass;

    if (! $values instanceof stdClass) {
        throw new RuntimeException("Expected an object for [$key].");
    }

    $result = [];

    foreach ($values as $name => $constraint) {
        if (! is_string($constraint)) {
            throw new RuntimeException("Invalid version constraint for [$name].");
        }

        if (str_starts_with($name, 'ext-') && ! preg_match('/^ext-[a-z0-9]+(?:[_-][a-z0-9]+)*$/D', $name)) {
            throw new RuntimeException("Invalid extension requirement [$name].");
        }

        if ($name === 'php' || str_starts_with($name, 'php-') || str_starts_with($name, 'ext-') || str_starts_with($name, 'lib-') || in_array($name, ['composer', 'composer-plugin-api', 'composer-runtime-api'], true)) {
            $result[$name] = $constraint;
        }
    }

    return $result;
}

try {
    $manifest = readObject($argv[1]);
    $lockPath = dirname($argv[1]).'/composer.lock';
    $lock = is_file($lockPath) ? readObject($lockPath) : new stdClass;
    $lock->platform = (object) requirements($manifest, 'require');
    $lock->{'platform-dev'} = (object) requirements($manifest, 'require-dev');
    $required = array_merge((array) $lock->platform, (array) $lock->{'platform-dev'});

    foreach (['packages', 'packages-dev'] as $key) {
        $lock->$key ??= [];

        if (! is_array($lock->$key)) {
            throw new RuntimeException("Expected an array for lock [$key].");
        }

        foreach ($lock->$key as $package) {
            if (! $package instanceof stdClass) {
                throw new RuntimeException('Invalid locked package.');
            }

            $required = array_merge($required, requirements($package, 'require'));
        }
    }

    // A temporary copy lets Composer check the selected manifest even with a
    // custom filename, while retaining locked transitive version constraints.
    $checkManifest = [
        'name' => 'ai-harness/cloud-platform-check',
        'version' => '1.0.0',
        'require' => $lock->platform,
        'require-dev' => $lock->{'platform-dev'},
    ];
    file_put_contents($argv[2].'/composer.json', json_encode($checkManifest, JSON_THROW_ON_ERROR));
    file_put_contents($argv[2].'/composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));
    $mapping = [
        'dom' => 'xml', 'simplexml' => 'xml', 'xmlreader' => 'xml', 'xmlwriter' => 'xml', 'xsl' => 'xml',
        'mysqli' => 'mysql', 'pdo_mysql' => 'mysql', 'pdo_sqlite' => 'sqlite3',
        'pdo_pgsql' => 'pgsql', 'pdo_odbc' => 'odbc', 'pdo_dblib' => 'sybase',
        'exif' => 'common', 'ftp' => 'common', 'gettext' => 'common', 'iconv' => 'common',
        'pdo' => 'common', 'posix' => 'common', 'shmop' => 'common', 'sockets' => 'common',
        'sysvmsg' => 'common', 'sysvsem' => 'common', 'sysvshm' => 'common', 'tokenizer' => 'common',
        'ctype' => 'common', 'fileinfo' => 'common', 'phar' => 'common',
        'zend-opcache' => 'opcache', 'pcntl' => 'cli',
    ];
    $packages = [];

    foreach (array_keys($required) as $name) {
        if (! str_starts_with($name, 'ext-')) {
            continue;
        }

        $extension = substr($name, 4);
        $loadedName = $extension === 'zend-opcache' ? 'Zend OPcache' : $extension;

        if (! extension_loaded($loadedName)) {
            $packages[] = $mapping[$extension] ?? str_replace('_', '-', $extension);
        }
    }

    foreach (array_unique($packages) as $package) {
        echo $package, "\n";
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Composer requirements: '.$exception->getMessage()."\n");
    exit(1);
}
PHP

        required_packages=()

        while IFS= read -r extension; do
            required_packages+=("php${php_version}-$extension")
        done < "$temporary_directory/packages"

        if (( ${#required_packages[@]} > 0 )); then
            printf 'Installing Composer PHP requirements: %s\n' "${required_packages[*]}"
            "${privilege[@]}" env DEBIAN_FRONTEND=noninteractive apt-get "${apt_options[@]}" install -y --no-install-recommends "${required_packages[@]}"
        fi

        # Do not load project plugins/scripts or inherit a custom COMPOSER path.
        # Composer verifies actual versions, ignoring config.platform emulation.
        COMPOSER=composer.json "$php_binary" /usr/bin/composer --no-plugins --no-scripts \
            --working-dir="$requirements_directory" check-platform-reqs --lock

        if ! command -v node >/dev/null 2>&1 || ! command -v npm >/dev/null 2>&1; then
            "${privilege[@]}" env DEBIAN_FRONTEND=noninteractive apt-get "${apt_options[@]}" install -y nodejs npm
        fi

        "$php_binary" --version
        "$php_binary" /usr/bin/composer --version
        ;;
    setup|maintain|cleanup)
        exec "$project_root/.ai-harness" cloud "$@"
        ;;
    *)
        printf 'Usage: ./.ai-harness-cloud provision|setup|maintain|cleanup\n' >&2
        exit 1
        ;;
esac
