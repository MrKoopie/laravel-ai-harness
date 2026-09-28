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

            source_directory="$(mktemp -d)"
            trap 'rm -rf -- "$source_directory"' EXIT
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

        if ! command -v node >/dev/null 2>&1 || ! command -v npm >/dev/null 2>&1; then
            "${privilege[@]}" env DEBIAN_FRONTEND=noninteractive apt-get "${apt_options[@]}" install -y nodejs npm
        fi

        php --version
        composer --version
        ;;
    setup|maintain|cleanup)
        exec "$project_root/.ai-harness" cloud "$@"
        ;;
    *)
        printf 'Usage: ./.ai-harness-cloud provision|setup|maintain|cleanup\n' >&2
        exit 1
        ;;
esac
