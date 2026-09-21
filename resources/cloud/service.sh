#!/usr/bin/env bash
set -euo pipefail

privilege=(env)

if [[ "$(id -u)" != 0 ]]; then
    privilege=(sudo -n)
fi

case "${1:-}" in
    mysql)
        "${privilege[@]}" service mysql start
        probe=("${privilege[@]}" mysql --no-defaults --no-login-paths --protocol=socket --socket="${AI_HARNESS_MYSQL_SOCKET:-/var/run/mysqld/mysqld.sock}" --user=root --connect-timeout=2 --execute='SELECT 1;')
        ;;
    redis)
        "${privilege[@]}" service redis-server start
        probe=(redis-cli -h 127.0.0.1 -p 6379 ping)
        ;;
    *)
        printf 'Unsupported cloud service.\n' >&2
        exit 1
        ;;
esac

for ((attempt=0; attempt<30; attempt++)); do
    if "${probe[@]}" >/dev/null 2>&1; then
        exit 0
    fi

    sleep 1
done

printf 'Cloud service %s did not become ready.\n' "$1" >&2
exit 1
