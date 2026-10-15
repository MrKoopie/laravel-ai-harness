#!/usr/bin/env bash
set -euo pipefail

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
cd "${project_root}"

if ! command -v ai-harness >/dev/null 2>&1; then
    printf 'Global AI Harness is unavailable. Run composer global require mrkoopie/laravel-ai-harness and add the Composer global bin directory to PATH.\n' >&2
    exit 127
fi

exec ai-harness "$@"
