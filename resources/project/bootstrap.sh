#!/usr/bin/env bash
set -euo pipefail

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
cd "${project_root}"

harness_binary="vendor/bin/ai-harness"

if [[ ! -x "${harness_binary}" ]]; then
    if command -v composer >/dev/null 2>&1; then
        composer install --no-interaction --prefer-dist
    elif command -v herd >/dev/null 2>&1; then
        herd composer install --no-interaction --prefer-dist
    else
        printf 'AI Harness requires Composer or Laravel Herd to install dependencies.\n' >&2
        exit 127
    fi
fi

if [[ ! -x "${harness_binary}" ]]; then
    printf 'mrkoopie/laravel-ai-harness is missing from composer.json or composer.lock.\n' >&2
    exit 1
fi

exec "${harness_binary}" "$@"
