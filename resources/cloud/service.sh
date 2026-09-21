#!/usr/bin/env bash
set -euo pipefail

privilege=(env)

if [[ "$(id -u)" != 0 ]]; then
    privilege=(sudo -n)
fi

case "${1:-}" in
    mysql)
        probe=("${privilege[@]}" env MYSQL_TEST_LOGIN_FILE=/dev/null mysql --no-defaults --protocol=socket --socket="${AI_HARNESS_MYSQL_SOCKET:-/var/run/mysqld/mysqld.sock}" --user=root --connect-timeout=2 --execute='SELECT 1;')

        if "${probe[@]}" >/dev/null 2>&1; then
            exit 0
        fi

        if ! "${privilege[@]}" service mysql start; then
            # Some cloud images have login-shell initialization that breaks SysV su.
            # Start the already initialized Ubuntu MySQL daemon without a login shell.
            "${privilege[@]}" install -d -o mysql -g mysql /var/run/mysqld
            "${privilege[@]}" mysqld --user=mysql --daemonize --bind-address=127.0.0.1 \
                --socket="${AI_HARNESS_MYSQL_SOCKET:-/var/run/mysqld/mysqld.sock}"
        fi
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
