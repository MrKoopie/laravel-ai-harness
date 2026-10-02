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
        # apt reads Signed-By keyrings as the _apt user. Only the keyrings go in
        # this directory; other temporary files stay private.
        keyring_directory="$(mktemp -d)"
        trap 'rm -rf -- "$temporary_directory" "$keyring_directory"' EXIT
        chmod 0755 "$keyring_directory"
        apt_options=()
        apt_sources="${AI_HARNESS_APT_SOURCE_LIST:-}"
        extra_sources="${AI_HARNESS_APT_EXTRA_SOURCES:-}"
        extra_extensions="${AI_HARNESS_PHP_EXTENSIONS:-}"
        php_repository="${AI_HARNESS_PHP_REPOSITORY:-auto}"

        case "$php_repository" in
            auto|sury|none) ;;
            *)
                printf 'AI_HARNESS_PHP_REPOSITORY must be auto, sury, or none.\n' >&2
                exit 1
                ;;
        esac

        # Stop on an invalid or unsupported major.minor version.
        validate_php_version() {
            if [[ ! "$1" =~ ^[0-9]{1,2}\.[0-9]{1,2}$ ]]; then
                printf 'Set AI_HARNESS_PHP_VERSION to an available major.minor PHP version with one or two digits per component; the selected version is invalid or undetermined.\n' >&2
                exit 1
            fi

            local major="${1%%.*}" minor="${1#*.}"

            if ((10#$major < 8 || (10#$major == 8 && 10#$minor < 2))); then
                printf 'Cloud setup requires PHP 8.2 or newer; select a compatible image or set AI_HARNESS_PHP_VERSION to a version available in its repositories.\n' >&2
                exit 1
            fi
        }

        # Validate an explicit version before a PHP repository is registered.
        if [[ -n "${AI_HARNESS_PHP_VERSION:-}" ]]; then
            validate_php_version "$AI_HARNESS_PHP_VERSION"
        fi

        php_repository_required=true

        if [[ "$php_repository" == auto ]]; then
            php_repository=none
            php_repository_required=false

            if [[ -n "${AI_HARNESS_PHP_VERSION:-}" ]]; then
                php_repository=sury
            fi
        fi

        if [[ -n "$extra_extensions" && ! "$extra_extensions" =~ ^[a-z0-9]+(-[a-z0-9]+)*(,[a-z0-9]+(-[a-z0-9]+)*)*$ ]]; then
            printf 'AI_HARNESS_PHP_EXTENSIONS must be comma-separated extension names such as imagick,soap.\n' >&2
            exit 1
        fi

        # Overrides for tests only.
        default_source_list="${AI_HARNESS_APT_DEFAULT_SOURCE_LIST:-/etc/apt/sources.list}"
        php_sources_directory="${AI_HARNESS_APT_SOURCES_DIR:-/etc/apt/sources.list.d}"

        if [[ -z "$apt_sources" && -f "$php_sources_directory/ubuntu.sources" ]]; then
            apt_sources="$php_sources_directory/ubuntu.sources"
        elif [[ -z "$apt_sources" && -f "$php_sources_directory/debian.sources" ]]; then
            apt_sources="$php_sources_directory/debian.sources"
        fi

        if [[ -n "$apt_sources" && ( "$apt_sources" != /* || ! -f "$apt_sources" ) ]]; then
            printf 'AI_HARNESS_APT_SOURCE_LIST must identify an existing absolute source-list path.\n' >&2
            exit 1
        fi

        source_paths=()

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
        fi

        # A PHP repository needs a base source list. Without one, the script
        # stops before it writes a key or a source.
        if [[ "$php_repository" == sury && -z "$apt_sources" ]]; then
            if [[ -f "$default_source_list" ]]; then
                apt_sources="$default_source_list"
            elif [[ "$php_repository_required" == true ]]; then
                printf 'A PHP repository requires a base source list; set AI_HARNESS_APT_SOURCE_LIST.\n' >&2
                exit 1
            else
                printf 'Continuing without a PHP repository; no base source list was found.\n' >&2
                php_repository=none
            fi
        fi

        if [[ "$php_repository" == sury ]]; then
            # Overrides for tests only.
            php_keyrings_directory="${AI_HARNESS_APT_KEYRINGS_DIR:-/etc/apt/keyrings}"
            os_release="${AI_HARNESS_OS_RELEASE:-/etc/os-release}"
            php_source="$php_sources_directory/ai-harness-php.sources"
            php_keyring="$php_keyrings_directory/ai-harness-php.gpg"
            # Awk -v values lose a backslash before a dot, so a bracket matches it.
            sury_pattern='^https?://packages[.]sury[.]org/php/?$'
            launchpad_pattern='^https?://ppa[.]launchpadcontent[.]net/ondrej/php/ubuntu/?$'
            sury_fingerprint=15058500A0235D97F5D10063B188E2B695BD4743
            launchpad_fingerprint=14AA40EC0831756756D7F66C4F4EA0AAE5267A6C
            sury_uri=https://packages.sury.org/php
            launchpad_uri=https://ppa.launchpadcontent.net/ondrej/php/ubuntu

            # Scan the file for enabled binary entries with a URI that matches the
            # pattern. Apt ignores comments and deb822 stanzas with "Enabled: no".
            # With a codename (for files that the script finds itself), an entry
            # counts only for that suite and with exactly one Signed-By keyring
            # file; in mode "entries" that keyring must also be in the trusted
            # list. Mode "entries" prints each entry that counts, mode "keyrings"
            # prints its keyring. Mode "binary" prints each binary entry for the
            # suite, whatever its keyring. Mode "any" prints each entry for the suite,
            # whatever its keyring, and also source-only (deb-src) entries,
            # because they conflict on Signed-By too.
            scan_source() {
                local format=list

                if [[ "$2" == *.sources ]]; then
                    format=deb822
                fi

                # Apt reads root-only source files too, so read them with privileges.
                [[ -f "$2" ]] && "${privilege[@]}" cat -- "$2" 2>/dev/null | awk -v mode="$1" -v format="$format" -v pattern="$3" -v codename="${4:-}" -v trusted="${5:-}" '
                    # Apt decodes percent escapes and treats the scheme and host
                    # without case; the path keeps its case. Compare the URI in
                    # that form.
                    function decode(uri,    decoded, digits, high, low) {
                        decoded = ""
                        digits = "0123456789abcdef"
                        while (match(uri, /%[0-9A-Fa-f][0-9A-Fa-f]/)) {
                            high = index(digits, tolower(substr(uri, RSTART + 1, 1))) - 1
                            low = index(digits, tolower(substr(uri, RSTART + 2, 1))) - 1
                            decoded = decoded substr(uri, 1, RSTART - 1) sprintf("%c", high * 16 + low)
                            uri = substr(uri, RSTART + 3)
                        }
                        return decoded uri
                    }
                    function same(uri,    head) {
                        head = ""
                        uri = decode(uri)
                        if (match(uri, /^[A-Za-z][A-Za-z0-9+.-]*:\/\/[^\/]*/)) {
                            head = tolower(substr(uri, 1, RLENGTH))
                            uri = substr(uri, RLENGTH + 1)
                        }
                        return (head uri) ~ pattern
                    }
                    function usable(path) {
                        if (codename == "" || mode == "any" || mode == "binary") return 1
                        if (path !~ /^\/[^,[:space:]]*$/) return 0
                        return mode == "keyrings" || index("\n" trusted "\n", "\n" path "\n") > 0
                    }
                    function reset() { matched = 0; enabled = 1; binary = 0; suite = (codename == ""); keyring = ""; keyrings = 0; field = "" }
                    function flush() {
                        if (matched && enabled && (binary || mode == "any") && suite && (keyrings <= 1 || mode == "any" || mode == "binary" || codename == "") && usable(keyring)) print (mode == "keyrings" ? keyring : "entry")
                        reset()
                    }
                    BEGIN { reset() }
                    format == "list" {
                        if ($0 ~ /^[[:space:]]*deb[[:space:]]/ || (mode == "any" && $0 ~ /^[[:space:]]*deb-src[[:space:]]/)) {
                            entry = $0
                            options = ""
                            sub(/^[[:space:]]*deb(-src)?[[:space:]]+/, "", entry)
                            if (match(entry, /^\[[^]]*\]/)) {
                                options = " " substr(entry, 2, RLENGTH - 2) " "
                                entry = substr(entry, RLENGTH + 1)
                                sub(/^[[:space:]]+/, "", entry)
                            }
                            split(entry, words, /[[:space:]]+/)
                            if (!same(words[1]) || (codename != "" && words[2] != codename)) next
                            path = ""
                            if (match(options, /[[:space:]]signed-by=[^[:space:]]+/)) path = substr(options, RSTART + 11, RLENGTH - 11)
                            if (usable(path)) print (mode == "keyrings" ? path : "entry")
                        }
                        next
                    }
                    /^[[:space:]]*#/ { next }
                    /^[[:space:]]*$/ { flush(); next }
                    /^[^[:space:]]/ { field = tolower($0); sub(/:.*/, "", field) }
                    {
                        value = $0
                        if (value ~ /^[^[:space:]]/) sub(/^[^:]*:/, "", value)
                        count = split(value, tokens, /[[:space:]]+/)
                    }
                    field == "uris" { for (i = 1; i <= count; i++) if (tokens[i] != "" && same(tokens[i])) matched = 1 }
                    field == "suites" && (" " value " ") ~ ("[[:space:]]" codename "[[:space:]]") { suite = 1 }
                    field == "types" && (" " tolower(value) " ") ~ /[[:space:]]deb[[:space:]]/ { binary = 1 }
                    field == "enabled" && tolower(value) ~ /^[[:space:]]*no[[:space:]]*$/ { enabled = 0 }
                    field == "signed-by" { for (i = 1; i <= count; i++) if (tokens[i] != "") { keyring = tokens[i]; keyrings++ } }
                    END { flush() }
                '
            }

            has_enabled_source() {
                [[ -n "$(scan_source entries "$@")" ]]
            }

            # Print the keyrings of the matching entries in the file that hold
            # only the key with the fingerprint.
            trusted_keyrings() {
                local keyring

                while IFS= read -r keyring; do
                    if key_has_fingerprint "$keyring" "$3" 2>/dev/null; then
                        printf '%s\n' "$keyring"
                    fi
                done < <(scan_source keyrings "$1" "$2" "$distribution_codename" | sort -u)
            }

            # Succeed when the keyring file holds exactly one key, with this
            # fingerprint, and the key is not expired (e) or revoked (r).
            # Signed-By trusts every key in the file.
            key_has_fingerprint() {
                if ! command -v gpg >/dev/null 2>&1; then
                    printf 'Checking the PHP repository key requires gpg.\n' >&2
                    return 1
                fi

                gpg --batch --with-colons --show-keys "$1" 2>/dev/null | awk -F: -v fingerprint="$2" '
                    $1 == "pub" { keys++; primary = 1; if ($2 ~ /[er]/) other = 1; next }
                    $1 == "fpr" { if (primary && $10 != fingerprint) other = 1; primary = 0; next }
                    { primary = 0 }
                    END { exit !(keys == 1 && !other) }
                '
            }

            # Print "selected" when a selected source has an enabled entry for the
            # pattern, else the first file in the sources directory with such an
            # entry for the current suite whose keyring holds only the key with the
            # fingerprint. A second entry for one repository makes apt reject
            # conflicting Signed-By values.
            find_enabled_source() {
                local candidate trusted

                # Without a selected base, apt and the fallback below use the default
                # list. A base that was not configured counts only with an entry for
                # the current suite; configured sources are used as is.
                if [[ -n "${AI_HARNESS_APT_SOURCE_LIST:-}" ]] && has_enabled_source "$apt_sources" "$1"; then
                    printf 'selected\n'
                    return
                elif [[ -z "${AI_HARNESS_APT_SOURCE_LIST:-}" ]] \
                    && [[ -n "$(scan_source binary "${apt_sources:-$default_source_list}" "$1" "$distribution_codename")" ]]; then
                    printf 'selected\n'
                    return
                fi

                for candidate in "${source_paths[@]}"; do
                    if [[ "$candidate" != "$php_source" ]] && has_enabled_source "$candidate" "$1"; then
                        printf 'selected\n'
                        return
                    fi
                done

                for candidate in "$php_sources_directory"/*.list "$php_sources_directory"/*.sources; do
                    [[ "$candidate" != "$php_source" ]] || continue
                    trusted="$(trusted_keyrings "$candidate" "$1" "$2")"

                    if [[ -n "$trusted" ]] && has_enabled_source "$candidate" "$1" "$distribution_codename" "$trusted"; then
                        printf '%s\n' "$candidate"
                        return
                    fi
                done
            }

            distribution_id=''
            distribution_codename=''

            if [[ -r "$os_release" ]]; then
                distribution_id="$(sed -nE 's/^ID="?([a-z0-9._-]+)"?$/\1/p' "$os_release" | head -n 1)"
                distribution_codename="$(sed -nE 's/^VERSION_CODENAME="?([a-z0-9._-]+)"?$/\1/p' "$os_release" | head -n 1)"
            fi

            existing_source="$(find_enabled_source "$sury_pattern" "$sury_fingerprint")"
            existing_pattern="$sury_pattern"
            existing_fingerprint="$sury_fingerprint"
            existing_uri="$sury_uri"
            php_repository_uri=''
            php_key_kind=''
            image_sury_source=''

            # A sury file in sources.list.d can be left by an earlier run. Use it
            # only when sury answers now, so that it cannot block the fallback.
            if [[ -n "$existing_source" && "$existing_source" != selected ]]; then
                image_sury_source="$existing_source"
                existing_source=''
            fi

            if [[ -z "$existing_source" ]]; then
                if [[ -z "$distribution_codename" ]]; then
                    printf 'Cannot read VERSION_CODENAME from %s.\n' "$os_release" >&2
                elif ! command -v curl >/dev/null 2>&1; then
                    printf 'Registering the PHP repository requires curl.\n' >&2
                else
                    curl_options=(-fsSL --retry 3 --connect-timeout 10 --max-time 60)

                    # curl 7.71 added --retry-all-errors; it also retries an HTTP 503.
                    if curl --help all 2>/dev/null | grep -q -- '--retry-all-errors'; then
                        curl_options+=(--retry-all-errors)
                    fi

                    # Succeed when the repository publishes a Release file for the
                    # current suite. curl exits with 60 or 77 when it cannot verify
                    # the TLS certificate, for example without ca-certificates.
                    repository_answers() {
                        local status=0

                        curl "${curl_options[@]}" -o /dev/null "$1/dists/$distribution_codename/Release" || status=$?

                        if ((status == 60 || status == 77)); then
                            printf 'curl cannot verify the TLS certificate of %s; install ca-certificates.\n' "$1" >&2
                        fi

                        return "$status"
                    }

                    # Sury is served through a CDN and also covers new Ubuntu releases.
                    # Launchpad is only a fallback: it often answered 503 since May 2026.
                    key_file="$keyring_directory/php-repository.gpg"
                    sury_usable=false

                    if repository_answers "$sury_uri"; then
                        if [[ -n "$image_sury_source" ]]; then
                            existing_source="$image_sury_source"
                            sury_usable=true
                        # apt.gpg is a binary keyring that apt can use directly. A key
                        # with another fingerprint sends the run to the fallback.
                        elif curl "${curl_options[@]}" -o "$key_file" "$sury_uri/apt.gpg" \
                            && key_has_fingerprint "$key_file" "$sury_fingerprint"; then
                            php_repository_uri="$sury_uri"
                            php_key_kind=sury
                            sury_usable=true
                        else
                            printf 'The sury signing key is not one valid key with fingerprint %s.\n' "$sury_fingerprint" >&2
                        fi
                    else
                        printf 'PHP repository %s does not answer for %s.\n' "$sury_uri" "$distribution_codename" >&2
                    fi

                    if [[ "$sury_usable" == false ]]; then
                        if [[ "$distribution_id" == ubuntu ]]; then
                            existing_source="$(find_enabled_source "$launchpad_pattern" "$launchpad_fingerprint")"
                            existing_pattern="$launchpad_pattern"
                            existing_fingerprint="$launchpad_fingerprint"
                            existing_uri="$launchpad_uri"

                            # A selected source is the user's choice; any other
                            # Launchpad source is used only when Launchpad answers.
                            if [[ "$existing_source" != selected ]]; then
                                if repository_answers "$launchpad_uri"; then
                                    if [[ -z "$existing_source" ]]; then
                                        php_repository_uri="$launchpad_uri"
                                        php_key_kind=launchpad
                                    fi
                                else
                                    existing_source=''
                                    printf 'PHP repository %s does not answer for %s.\n' "$launchpad_uri" "$distribution_codename" >&2
                                fi
                            fi
                        fi
                    fi
                fi
            fi

            if [[ -n "$existing_source" ]]; then
                # Write a new entry with the verified keyring of the found file, so
                # that other repositories, suites, components and options in that
                # file cannot change the update.
                if [[ "$existing_source" != selected ]]; then
                    reused_keyring="$(trusted_keyrings "$existing_source" "$existing_pattern" "$existing_fingerprint" | head -n 1)"
                    # Copy the keyring with mode 0644, so that the _apt user can
                    # read it also when the file or its directory is private. Apt
                    # reads an .asc keyring as ASCII-armored, so keep that extension.
                    reused_copy="$keyring_directory/php-repository-reused.gpg"

                    if [[ "$reused_keyring" == *.asc ]]; then
                        reused_copy="$keyring_directory/php-repository-reused.asc"
                    fi

                    "${privilege[@]}" cat -- "$reused_keyring" > "$reused_copy"
                    chmod 0644 "$reused_copy"
                    reused_source="$temporary_directory/php-repository-reused.sources"
                    printf 'Types: deb\nURIs: %s\nSuites: %s\nComponents: main\nSigned-By: %s\n' \
                        "$existing_uri" "$distribution_codename" "$reused_copy" > "$reused_source"
                    source_paths+=("$reused_source")
                fi
            elif [[ -n "$php_key_kind" ]]; then
                if [[ "$php_key_kind" == launchpad ]]; then
                    curl "${curl_options[@]}" \
                        "https://keyserver.ubuntu.com/pks/lookup?op=get&options=mr&search=0x$launchpad_fingerprint" \
                        | gpg --batch --yes --dearmor -o "$key_file"

                    if ! key_has_fingerprint "$key_file" "$launchpad_fingerprint"; then
                        printf 'The Launchpad signing key is not one valid key with fingerprint %s.\n' "$launchpad_fingerprint" >&2
                        exit 1
                    fi
                fi

                # A second entry for one repository with another Signed-By value
                # makes later apt commands fail. When sources.list.d already has an
                # entry that cannot be used, the new entry and its key are used only
                # for this run, so the persistent keyring of an earlier source stays.
                conflicting_source=''

                for candidate in "$php_sources_directory"/*.list "$php_sources_directory"/*.sources; do
                    if [[ "$candidate" != "$php_source" ]] \
                        && [[ -n "$(scan_source any "$candidate" "$existing_pattern" "$distribution_codename")" ]]; then
                        conflicting_source="$candidate"
                        break
                    fi
                done

                if [[ -n "$conflicting_source" ]]; then
                    chmod 0644 "$key_file"
                    printf 'Types: deb\nURIs: %s\nSuites: %s\nComponents: main\nSigned-By: %s\n' \
                        "$php_repository_uri" "$distribution_codename" "$key_file" > "$temporary_directory/php-repository.sources"
                    printf 'Using PHP repository %s %s for this run only; %s already has an entry for it.\n' \
                        "$php_repository_uri" "$distribution_codename" "$conflicting_source" >&2
                    source_paths+=("$temporary_directory/php-repository.sources")
                else
                    printf 'Types: deb\nURIs: %s\nSuites: %s\nComponents: main\nSigned-By: %s\n' \
                        "$php_repository_uri" "$distribution_codename" "$php_keyring" > "$temporary_directory/php-repository.sources"
                    "${privilege[@]}" install -d -m 0755 -- "$php_keyrings_directory" "$php_sources_directory"
                    "${privilege[@]}" install -m 0644 -- "$key_file" "$php_keyring"
                    "${privilege[@]}" install -m 0644 -- "$temporary_directory/php-repository.sources" "$php_source"
                    printf 'Registered PHP repository %s %s.\n' "$php_repository_uri" "$distribution_codename"
                    source_paths+=("$php_source")
                fi
            elif [[ "$php_repository_required" == true ]]; then
                printf 'No PHP repository answers; allow packages.sury.org in the network policy of the environment.\n' >&2
                exit 1
            else
                printf 'Continuing without a PHP repository; allow packages.sury.org in the network policy of the environment.\n' >&2
            fi
        fi

        if (( ${#source_paths[@]} > 0 )) && [[ -z "$apt_sources" ]]; then
            if [[ -f "$default_source_list" ]]; then
                apt_sources="$default_source_list"
            else
                printf 'Extra apt sources require a base source list; set AI_HARNESS_APT_SOURCE_LIST.\n' >&2
                exit 1
            fi
        fi

        if [[ -n "$apt_sources" ]]; then
            apt_options=(-o "Dir::Etc::sourcelist=$apt_sources" -o 'Dir::Etc::sourceparts=-')
        fi

        if (( ${#source_paths[@]} > 0 )); then
            source_directory="$temporary_directory/sources"
            mkdir -- "$source_directory"
            source_index=0

            for source_path in "${source_paths[@]}"; do
                cp -- "$source_path" "$source_directory/$source_index.${source_path##*.}"
                source_index=$((source_index + 1))
            done

            apt_options=(-o "Dir::Etc::sourcelist=$apt_sources" -o "Dir::Etc::sourceparts=$source_directory")
        fi

        # Mirrors and PPAs can answer 503 for a moment; retry each download.
        apt_options=(-o 'Acquire::Retries=5' "${apt_options[@]}")

        "${privilege[@]}" apt-get "${apt_options[@]}" update
        php_version="${AI_HARNESS_PHP_VERSION:-}"

        if [[ -z "$php_version" ]]; then
            php_version="$(LC_ALL=C apt-cache "${apt_options[@]}" depends php-cli | sed -nE 's/^[[:space:]]*Depends: php([0-9]+\.[0-9]+)-cli$/\1/p' | head -n 1)"
        fi

        validate_php_version "$php_version"

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

        "$php_binary" -- "$composer_json" "$requirements_directory" > "$temporary_directory/candidates.json" <<'PHP'
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
        'name' => $manifest->name ?? 'ai-harness/cloud-platform-check',
        'version' => $manifest->version ?? '1.0.0',
        'provide' => (object) requirements($manifest, 'provide'),
        'replace' => (object) requirements($manifest, 'replace'),
        'require' => $lock->platform,
        'require-dev' => $lock->{'platform-dev'},
    ];
    file_put_contents($argv[2].'/composer.json', json_encode($checkManifest, JSON_THROW_ON_ERROR));
    file_put_contents($argv[2].'/composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));
    $mapping = [
        'dom' => 'xml', 'simplexml' => 'xml', 'xmlreader' => 'xml', 'xmlwriter' => 'xml', 'xsl' => 'xml',
        'mysqlnd' => 'mysql', 'mysqli' => 'mysql', 'pdo_mysql' => 'mysql', 'pdo_sqlite' => 'sqlite3',
        'pdo_firebird' => 'interbase', 'pdo_pgsql' => 'pgsql', 'pdo_odbc' => 'odbc', 'pdo_dblib' => 'sybase',
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
            $packages[$name] = $mapping[$extension] ?? str_replace('_', '-', $extension);
        }
    }

    echo json_encode($packages, JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Composer requirements: '.$exception->getMessage()."\n");
    exit(1);
}
PHP

        # Ask Composer to resolve providers/replacements and their constraints
        # before installing anything from the project's extension requirements.
        platform_status=0
        COMPOSER=composer.json "$php_binary" /usr/bin/composer --no-plugins --no-scripts \
            --working-dir="$requirements_directory" check-platform-reqs --lock --format=json \
            > "$temporary_directory/platform.json" || platform_status=$?

        if (( platform_status > 2 )); then
            exit "$platform_status"
        fi

        "$php_binary" -- "$temporary_directory/candidates.json" "$temporary_directory/platform.json" > "$temporary_directory/packages" <<'PHP'
<?php

try {
    $candidates = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $results = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($results) || ! array_is_list($results)) {
        throw new RuntimeException('Expected a list from Composer platform validation.');
    }

    $packages = [];

    foreach ($results as $result) {
        if (! is_array($result) || ! isset($result['name'], $result['status']) || ! in_array($result['status'], ['success', 'missing', 'failed'], true)) {
            throw new RuntimeException('Invalid Composer platform validation result.');
        }

        if ($result['status'] !== 'success' && isset($candidates[$result['name']])) {
            $packages[] = $candidates[$result['name']];
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
