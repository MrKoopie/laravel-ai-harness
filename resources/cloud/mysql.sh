#!/usr/bin/env bash
set -euo pipefail

action="${1:?Missing database action}"
database="${2:?Missing development database}"
testing="${3:?Missing testing database}"
username="${4:?Missing database user}"

for identifier in "$database" "$testing" "$username"; do
    if [[ ! "$identifier" =~ ^[a-z0-9_]+$ || ${#identifier} -gt 64 ]]; then
        printf 'Invalid cloud database identifier.\n' >&2
        exit 1
    fi
done

if [[ "$testing" != "${database}_testing" || "$username" != harness_* ]]; then
    printf 'Unexpected cloud database relationship.\n' >&2
    exit 1
fi

socket="${AI_HARNESS_MYSQL_SOCKET:-/var/run/mysqld/mysqld.sock}"

if [[ "$socket" != /* ]]; then
    printf 'AI_HARNESS_MYSQL_SOCKET must be an absolute local socket path.\n' >&2
    exit 1
fi

privilege=(env)

if [[ "$(id -u)" != 0 ]]; then
    privilege=(sudo -n)
fi

# Ignore user option files and login paths: cleanup must never connect to a remote host.
mysql_command=("${privilege[@]}" mysql --no-defaults --no-login-paths --protocol=socket --socket="$socket" --user=root --connect-timeout=5)

case "$action" in
    setup)
        database_grant="${database//_/\\_}"
        testing_grant="${testing//_/\\_}"
        "${mysql_command[@]}" --execute="CREATE DATABASE IF NOT EXISTS \`$database\`; CREATE DATABASE IF NOT EXISTS \`$testing\`; CREATE USER IF NOT EXISTS '$username'@'localhost' IDENTIFIED BY 'harness'; GRANT ALL PRIVILEGES ON \`$database_grant\`.* TO '$username'@'localhost'; GRANT ALL PRIVILEGES ON \`$testing_grant\`.* TO '$username'@'localhost'; GRANT ALL PRIVILEGES ON \`${testing_grant}\\_%\`.* TO '$username'@'localhost';"
        ;;
    cleanup)
        # Capture first so failure to enumerate schemas cannot be mistaken for success.
        schemas="$("${mysql_command[@]}" --batch --skip-column-names --execute='SHOW DATABASES;')"
        worker_pattern="^${testing}(_test)?_[0-9]+$"

        while IFS= read -r name; do
            if [[ "$name" == "$testing" || "$name" =~ $worker_pattern ]]; then
                "${mysql_command[@]}" --execute="DROP DATABASE IF EXISTS \`$name\`;"
            fi
        done <<< "$schemas"
        ;;
    *)
        printf 'Unsupported database action.\n' >&2
        exit 1
        ;;
esac
