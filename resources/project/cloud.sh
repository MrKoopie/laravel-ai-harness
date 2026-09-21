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

        php_version="${AI_HARNESS_PHP_VERSION:-8.3}"

        if [[ ! "$php_version" =~ ^[0-9]+\.[0-9]+$ ]]; then
            printf 'AI_HARNESS_PHP_VERSION must be a major.minor version.\n' >&2
            exit 1
        fi

        apt_options=()
        apt_sources="${AI_HARNESS_APT_SOURCE_LIST:-}"

        if [[ -z "$apt_sources" && -f /etc/apt/sources.list.d/ubuntu.sources ]]; then
            apt_sources=/etc/apt/sources.list.d/ubuntu.sources
        elif [[ -z "$apt_sources" && -f /etc/apt/sources.list.d/debian.sources ]]; then
            apt_sources=/etc/apt/sources.list.d/debian.sources
        fi

        if [[ -n "$apt_sources" ]]; then
            if [[ "$apt_sources" != /* || ! -f "$apt_sources" ]]; then
                printf 'AI_HARNESS_APT_SOURCE_LIST must identify an existing absolute source-list path.\n' >&2
                exit 1
            fi

            apt_options=(-o "Dir::Etc::sourcelist=$apt_sources" -o 'Dir::Etc::sourceparts=-')
        fi

        "${privilege[@]}" apt-get "${apt_options[@]}" update
        "${privilege[@]}" env DEBIAN_FRONTEND=noninteractive apt-get "${apt_options[@]}" install -y --no-install-recommends \
            "php${php_version}-cli" "php${php_version}-mysql" "php${php_version}-sqlite3" \
            "php${php_version}-mbstring" "php${php_version}-xml" "php${php_version}-curl" \
            "php${php_version}-zip" "php${php_version}-intl" "php${php_version}-bcmath" \
            "php${php_version}-redis" \
            composer git unzip ca-certificates mysql-server redis-server
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
