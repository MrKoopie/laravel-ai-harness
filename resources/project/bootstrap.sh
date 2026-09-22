#!/usr/bin/env bash
set -euo pipefail

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
cd "${project_root}"

harness_binary="vendor/bin/ai-harness"
cloud=false

case "${AI_HARNESS_ENV:-}" in
    claude-cloud|codex-cloud) cloud=true ;;
    local) ;;
    '')
        if [[ "${CLAUDE_CODE_REMOTE:-}" == true ]]; then
            cloud=true
        fi
        ;;
    *)
        printf 'AI_HARNESS_ENV must be local, claude-cloud, or codex-cloud.\n' >&2
        exit 1
        ;;
esac

if [[ "${1:-}" == hook && "${2:-}" == claude && "${3:-}" == session-end && "$cloud" == false ]]; then
    exit 0
fi

if [[ ! -x "${harness_binary}" ]]; then
    if [[ "${1:-}" == cleanup || ( "${1:-}" == cloud && "${2:-}" == cleanup ) || ( "${1:-}" == hook && "${3:-}" == session-end ) ]]; then
        printf 'Cannot clean up without installed harness dependencies; restore vendor and retry cleanup.\n' >&2
        exit 1
    fi

    install_arguments=(install --no-interaction --prefer-dist)

    if [[ "$cloud" == true ]]; then
        if [[ ! -f composer.lock ]]; then
            printf 'Cloud setup requires a committed composer.lock.\n' >&2
            exit 1
        fi

        preference="${AI_HARNESS_COMPOSER_PREFER:-dist}"

        if [[ "$preference" != dist && "$preference" != source ]]; then
            printf 'AI_HARNESS_COMPOSER_PREFER must be dist or source.\n' >&2
            exit 1
        fi

        # The package must exist before it can configure Laravel for Composer scripts.
        install_arguments=(install --no-interaction "--prefer-$preference" --no-scripts)
        export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_DEV=0
    fi

    if command -v composer >/dev/null 2>&1; then
        composer "${install_arguments[@]}"
    elif [[ "$cloud" == false ]] && command -v herd >/dev/null 2>&1; then
        herd composer "${install_arguments[@]}"
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
