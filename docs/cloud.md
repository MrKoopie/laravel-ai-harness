# Cloud environments

The harness can prepare Claude Code and Codex cloud containers. In a cloud environment, the harness always uses native PHP and local services in the container. It ignores the local Herd, Valet, and Sail settings.

## Before you start

1. Set `cloud=true` in `.ai-harness.config`. This is the default.
2. Run `./.ai-harness update` in the project.
3. Commit these generated files:
   - `.ai-harness`
   - `.ai-harness-cloud`
   - `AGENTS.md`
   - `.claude/settings.json`
4. Commit `composer.lock`. Cloud setup does not work without it.
5. For frontend projects, commit `package-lock.json`.

`.ai-harness-cloud` can install system tools before `vendor/` exists.

## Detection

The harness uses cloud mode only when it gets a clear signal:

| Signal | Environment |
| --- | --- |
| `AI_HARNESS_ENV=claude-cloud` | Claude cloud |
| `AI_HARNESS_ENV=codex-cloud` | Codex cloud |
| `AI_HARNESS_ENV=local` | Local. This overrides automatic detection. |
| `CLAUDE_CODE_REMOTE=true` (set by Claude) | Claude cloud, when `AI_HARNESS_ENV` is empty |

An unknown `AI_HARNESS_ENV` value stops the command with an error. Linux, the checkout path, and installed agent programs do not cause cloud mode.

## Cloud configuration keys

The cloud keys in `.ai-harness.config` are independent of `worktrees`:

```ini
cloud=true
cloud_services=mysql,redis
cloud_migrate=false
cloud_seed=false
cloud_build=false
cloud_browser=false
```

| Key | Effect |
| --- | --- |
| `cloud_services` | `mysql` is the default. Redis uses the PHP Redis extension. Set `cloud_services=` for SQLite and no services. |
| `cloud_migrate` | Run `artisan migrate` for the development and testing databases. The harness never runs `migrate:fresh`. |
| `cloud_seed` | Run the development seeder on each setup. Requires `cloud_migrate=true`. Use it only with seeders that are safe to run again. |
| `cloud_build` | Run `npm run build`. |
| `cloud_browser` | Install Chromium and its OS dependencies through Playwright. Playwright must already be in the project dependencies. |

These keys never change local setup.

## Claude cloud

1. In the Claude cloud environment settings, set this environment variable:

   ```dotenv
   AI_HARNESS_ENV=claude-cloud
   ```

   Claude also sets `CLAUDE_CODE_REMOTE=true`, but the setup script runs before the agent starts. Thus, the setup script needs `AI_HARNESS_ENV`.

2. Set the environment setup script. It runs from the repository root:

   ```bash
   ./.ai-harness-cloud provision
   ```

   This installs the system packages. The provider can cache the result.

3. The `SessionStart` hook in `.claude/settings.json` prepares the project. It runs for new clones and resumed sessions, also with `worktrees=false`. It installs the locked dependencies and starts or checks the services in each session.

4. The `SessionEnd` hook removes the owned testing databases. It has a 60-second timeout. If it fails, run `./.ai-harness cloud cleanup`.

### Multi-repository sessions

Claude does not load repository hooks in multi-repository sessions. In each checkout, run the setup yourself:

```bash
./.ai-harness-cloud setup
```

Do not use a cached provisioning script to restart services.

### Time and network limits

The Claude setup time is approximately five minutes. Put system provisioning in the environment setup script. Put project setup in `SessionStart`.

Package installs need the registry and archive hosts in the network allowlist of the environment. When provisioning adds the PHP repository, also add `packages.sury.org` to the allowlist. Refer to [PHP repository](#php-repository).

Refer to [Claude cloud environments](https://code.claude.com/docs/en/cloud-environments) and [SessionEnd hooks](https://code.claude.com/docs/en/hooks#sessionend).

## Codex cloud

1. In the Codex cloud environment settings, set this **environment variable**:

   ```dotenv
   AI_HARNESS_ENV=codex-cloud
   ```

2. Set the setup script. It runs from the repository root:

   ```bash
   ./.ai-harness-cloud provision
   ./.ai-harness-cloud setup
   ```

3. Set the maintenance script. It prepares the selected branch after Codex restores a cache:

   ```bash
   ./.ai-harness-cloud maintain
   ```

You must set both the setup script and the maintenance script. The generated `.codex/environments/environment.toml` is for **local** worktrees only. It does not configure Codex cloud.

### Network access

The setup phase has internet access. A dependency refresh needs network access in the phase where it runs. If agent internet access is disabled, install the necessary test tools before the agent phase.

### Secrets

Codex environment secrets are available only during setup.

- Use secrets only for private dependency authentication during setup, for example `COMPOSER_AUTH`. Refer to [Private packages](private-packages.md).
- Make sure that maintenance can install branch-specific dependencies without these secrets.
- Do not put package credentials in tracked files.

### Cleanup

Codex has no guaranteed teardown hook. Run `./.ai-harness cloud cleanup` when necessary. The provider discards the container at the end.

Refer to [Codex cloud environments](https://learn.chatgpt.com/docs/environments/cloud-environment).

## Provisioning

`./.ai-harness-cloud provision` installs the system packages.

### Requirements

- Ubuntu or Debian with `apt-get`.
- Root access, or `sudo` without a password.
- Access to the apt repositories.
- `curl`, `gpg` and `ca-certificates`, when provisioning adds the PHP repository. `gpg` checks the repository signing key. Without `ca-certificates`, curl cannot verify the repository certificates, and provisioning says so.

### What it installs

- The default PHP CLI of the distribution, or the version in `AI_HARNESS_PHP_VERSION`. PHP 8.2 or newer is necessary.
- The common Laravel PHP extensions.
- Composer.
- MySQL and Redis.
- Node.js and npm, when they are not installed.

Set the Node.js version in the provider image or settings, so that it agrees with the `engines` field of your project.

### PHP extensions from Composer

Provisioning reads the `ext-*` requirements from:

- `require` and `require-dev` in the project `composer.json`.
- The runtime requirements of all packages in `packages` and `packages-dev` in the adjacent `composer.lock`.

It installs the missing extensions for the selected PHP version:

- It skips extensions that are already loaded.
- It skips requirements that Composer `provide` or `replace` constraints satisfy.
- It maps grouped extensions to their package. For example, `ext-dom` becomes `xml` and `ext-pdo_mysql` becomes `mysql`.

Then Composer checks the actual PHP and extension versions, including the development requirements. It does not run project plugins or scripts. Provisioning does not change project files.

Provisioning stops with an error when a package is missing or a version is not compatible. After such a failure, it does not select a different PHP version automatically. It does not add repositories other than the [PHP repository](#php-repository), and it does not build PECL extensions from source. Only `AI_HARNESS_PHP_VERSION` selects a different PHP version.

### Apt sources

When `ubuntu.sources` or `debian.sources` exists, provisioning uses only that file. This prevents errors from unrelated image repositories that the cloud proxy can block. Package signature verification stays enabled.

All apt commands retry a failed download five times (`Acquire::Retries=5`).

### PHP repository

When you set `AI_HARNESS_PHP_VERSION`, provisioning adds the PHP repository of Ondřej Surý. The distribution often does not have the version that you select.

1. It reads `VERSION_CODENAME` from `/etc/os-release`.
2. It tries `https://packages.sury.org/php/`. This repository uses a CDN, and it is the only source for new Ubuntu releases such as 26.04. The signing key is `https://packages.sury.org/php/apt.gpg`. The script checks that the file holds only the key with fingerprint `15058500A0235D97F5D10063B188E2B695BD4743`, and that this key is not expired or revoked. Another key, or an expired or revoked key, makes the script use the fallback.
3. On Ubuntu only: when sury does not answer or its key does not match, it tries the ondrej/php PPA at `https://ppa.launchpadcontent.net/ondrej/php/ubuntu`. It gets the PPA signing key from `keyserver.ubuntu.com` and checks the fingerprint `14AA40EC0831756756D7F66C4F4EA0AAE5267A6C`.
4. It writes the key to `/etc/apt/keyrings/ai-harness-php.gpg` and the source to `/etc/apt/sources.list.d/ai-harness-php.sources`, with `Signed-By`. Then it uses the source together with the base source.

To find a repository that answers, provisioning requests `dists/<codename>/Release` with `curl --retry 3 --retry-all-errors`. Since May 2026, the Launchpad servers often answer `503 Service Unavailable`. Thus, the PPA is only a fallback.

- Add `packages.sury.org` to the network allowlist of the cloud environment. For the fallback, also add `ppa.launchpadcontent.net` and `keyserver.ubuntu.com`.
- Do not use `https://ppa.launchpad.net`. Its TLS certificate does not agree with the host name. Only `ppa.launchpadcontent.net` supports HTTPS.
- When a selected source already has an enabled entry with the URI `https://packages.sury.org/php/`, provisioning uses it and adds no second source. A file in `/etc/apt/sources.list.d` with such an entry is used only when sury answers. The same applies to an enabled entry with the URI `https://ppa.launchpadcontent.net/ondrej/php/ubuntu` when sury does not answer; a file in `/etc/apt/sources.list.d` with such an entry is used only when Launchpad answers. Apt rejects two entries for one repository with different `Signed-By` values. Provisioning compares URIs as apt does: without case for the scheme and host, with case for the path, and with percent escapes such as `%2F` decoded. A source set with `AI_HARNESS_APT_SOURCE_LIST` or `AI_HARNESS_APT_EXTRA_SOURCES` is used as it is when it has a binary entry with the `main` component that apt reads for the native architecture, in any suite. A base source list that provisioning finds itself (`ubuntu.sources`, `debian.sources` or `/etc/apt/sources.list`) counts only with a binary entry for the current suite with the `main` component that apt reads for the native architecture (`arch`, `arch+=`, `arch-=`, `Architectures`, `Architectures-Add` and `Architectures-Remove`). When it has only other entries for the repository and the current suite, such as a `deb-src` entry or an entry for another architecture, provisioning stops, because a new entry would conflict with them on `Signed-By`. The same applies to a source set with `AI_HARNESS_APT_SOURCE_LIST` or `AI_HARNESS_APT_EXTRA_SOURCES` that has only such entries, when no selected source has a binary entry for the repository. Commented entries and deb822 stanzas with `Enabled: no` (or another apt false value such as `false`, `off` or `0`) do not count. A source-only (`deb-src`) entry is not reused, but it counts as an entry for the repository in the check below. An entry in `/etc/apt/sources.list.d` counts only for the current suite, and only when it has exactly one `Signed-By` keyring file that holds only the pinned key of that repository. For such a file, provisioning does not use the file itself: it writes a new entry for the repository, the current suite and `main`, signed by a copy of that keyring with mode 0644 (apt reads the keyring as the `_apt` user; a copy of an ASCII-armored `.asc` keyring keeps the `.asc` extension), so other repositories, suites, components and options in the file (such as `Trusted: yes`) cannot change the update. When `/etc/apt/sources.list.d` has an enabled entry for the repository and the current suite that does not count (for example a `deb-src` entry, or an entry with another key, more than one `Signed-By` value or an embedded key), provisioning uses its new entry and the verified key for that run only and does not change `/etc/apt/keyrings/ai-harness-php.gpg`, because a second entry with another `Signed-By` value makes later apt commands fail.
- A PHP repository needs a base source list (`ubuntu.sources`, `debian.sources`, `/etc/apt/sources.list`, or `AI_HARNESS_APT_SOURCE_LIST`). Without one, provisioning writes no key and no source. With `sury` it stops; with `auto` it continues without a PHP repository. With a PHP repository, apt always uses only the base source list and the selected sources, so an old PHP source in `/etc/apt/sources.list.d` that does not answer is not used.
- Provisioning checks `AI_HARNESS_PHP_VERSION` before it adds a repository. An invalid version, or a version before 8.2, stops provisioning without changes.
- With curl before 7.71, the probes use `--retry 3` without `--retry-all-errors`.
- With the default `AI_HARNESS_PHP_REPOSITORY=auto`, provisioning continues with the configured sources when no PHP repository answers. Then the package install shows the error. With `sury`, provisioning stops immediately.
- Set `AI_HARNESS_PHP_REPOSITORY=none` to use only the configured sources.

Package signature verification stays enabled. The harness does not add other third-party apt repositories.

### phpenv shims

Some images put phpenv shims first in `PATH`. Then the provisioned PHP is not used. Put `/usr/bin` first in the setup script, the maintenance script, and each shell or hook that starts project commands:

```bash
export PATH="/usr/bin:$PATH"
```

`update-alternatives` selects `/usr/bin/php`, but it cannot override an earlier `PATH` entry.

## Environment variables

Set these variables in the cloud environment. They are not `.ai-harness.config` keys.

| Variable | Used by | Description |
| --- | --- | --- |
| `AI_HARNESS_ENV` | All | `claude-cloud`, `codex-cloud`, or `local`. |
| `AI_HARNESS_PHP_VERSION` | `provision` | PHP version to install, for example `8.4`. Provisioning adds the [PHP repository](#php-repository) for it. Default: the distribution default. |
| `AI_HARNESS_PHP_REPOSITORY` | `provision` | `auto` (default), `sury`, or `none`. `auto` adds the [PHP repository](#php-repository) only when `AI_HARNESS_PHP_VERSION` is set. `sury` always adds it and stops when no repository answers. `none` adds no repository. |
| `AI_HARNESS_PHP_EXTENSIONS` | `provision` | More extensions, comma-separated and lowercase without spaces, for example `imagick,soap`. |
| `AI_HARNESS_APT_SOURCE_LIST` | `provision` | Absolute path to an existing apt source list. Overrides the automatic selection. |
| `AI_HARNESS_APT_EXTRA_SOURCES` | `provision` | Colon-separated absolute paths to more `.list` or `.sources` files. |
| `AI_HARNESS_COMPOSER_JSON` | `provision` | Path to the Composer manifest for extension detection. Default: `composer.json` in the project root. |
| `AI_HARNESS_COMPOSER_PREFER` | Bootstrap | `dist` (default) or `source`. |
| `AI_HARNESS_MYSQL_SOCKET` | `setup` | Absolute path to a different local MySQL socket. |
| `COMPOSER_AUTH` | Composer | Credentials for private packages, as JSON. Composer reads it. Refer to [Private packages](private-packages.md). |

An empty variable uses the default.

### `AI_HARNESS_PHP_VERSION`

The version must exist in the configured apt repositories or in the [PHP repository](#php-repository). When the default PHP of the image is older than 8.2, set a version such as `8.5`.

### `AI_HARNESS_PHP_EXTENSIONS`

The values are package suffixes. With `AI_HARNESS_PHP_VERSION=8.5` and `AI_HARNESS_PHP_EXTENSIONS=imagick,soap`, provisioning adds `php8.5-imagick` and `php8.5-soap`. It also installs the standard extensions. The selected repositories must supply these versioned packages.

### `AI_HARNESS_APT_EXTRA_SOURCES`

- Each path must be an existing, readable `.list` or deb822 `.sources` file.
- Provisioning uses these files together with the base source for `apt update`, PHP version discovery, and all package installs.
- Other files in `sources.list.d` stay excluded.
- When `ubuntu.sources` and `debian.sources` do not exist, the base is `/etc/apt/sources.list`. If that file also does not exist, set `AI_HARNESS_APT_SOURCE_LIST`.
- The original source files do not change. The temporary keyrings are in a separate directory that the `_apt` user can read; other temporary files stay private. Both directories are removed at the end.

### `AI_HARNESS_COMPOSER_JSON`

- A relative path starts from the project root (where `.ai-harness-cloud` is).
- An absolute path is also valid.
- Provisioning reads `composer.lock` from the same directory, also when the manifest has a different file name.
- A missing file, an unreadable file, or invalid JSON stops provisioning with an error.
- This variable has no effect on the working directory or the Composer manifest of `setup` and `maintain`.

For a manifest in a subdirectory:

```bash
AI_HARNESS_COMPOSER_JSON=backend/composer.json ./.ai-harness-cloud provision
```

### `AI_HARNESS_COMPOSER_PREFER`

Set `source` to install packages from Git when archive downloads are blocked. This also applies to the first bootstrap install. It is an explicit network workaround. It is not an automatic fallback, and it does not disable TLS checks.

### Example: PHP 8.5 with more extensions

Use this in the environment setup script:

```bash
export PATH="/usr/bin:$PATH"
export AI_HARNESS_PHP_VERSION=8.5
export AI_HARNESS_PHP_EXTENSIONS=pcov,imagick,soap
./.ai-harness-cloud provision
./.ai-harness-cloud setup
```

Provisioning adds the [PHP repository](#php-repository). The network policy must allow `packages.sury.org`.

Do not also add the ondrej/php PPA from `ppa.launchpad.net` through `AI_HARNESS_APT_EXTRA_SOURCES`. When that host answers `503`, `apt-get update` fails.

### Example: a different PHP version from your own repository

Add a trusted repository and its signing key to the image. Then use the actual path of the source file:

```bash
export PATH="/usr/bin:$PATH"
export AI_HARNESS_PHP_VERSION=8.5
export AI_HARNESS_PHP_REPOSITORY=none
export AI_HARNESS_APT_EXTRA_SOURCES=/etc/apt/sources.list.d/php.sources
./.ai-harness-cloud provision
```

- The repository file must agree with the distribution and release of the image.
- The signing key must already be available.
- The cloud network policy must allow the repository hosts.

A source file does not create the repository. It does not bypass apt signature checks.

## Project setup

`./.ai-harness cloud setup` (or `./.ai-harness-cloud setup`) prepares the project. `maintain` does the same steps. With `cloud=false`, both do nothing.

1. It makes sure that `.env` exists. It copies `.env.example` when necessary.
2. It replaces the standard Laravel database and Redis connection values with the local cloud values. It clears the default config cache and normalizes `APP_CONFIG_CACHE`. Without MySQL, it creates `database/database.sqlite`.
3. It starts each service in `cloud_services`. With MySQL, it creates the databases and user for this checkout.
4. It installs the development dependencies from `composer.lock` and runs `composer check-platform-reqs`. It does not skip platform requirements.
5. It runs `artisan config:clear`, generates `APP_KEY` when it is empty, and updates inline PHPUnit connection overrides.
6. When enabled, it runs `artisan migrate` for the development and testing databases, then `artisan db:seed`.
7. When `package.json` exists, it runs `npm ci --include=dev`. This needs `package-lock.json`. Other package managers need project-specific setup.
8. When enabled, it runs `npm run build` and installs Playwright Chromium.

For commands that the harness runs, it removes inherited standard connection variables such as `DB_*` and `REDIS_*`. Custom connection names and configuration stay the responsibility of the application.

### MySQL in the cloud

- The harness connects as administrator only through the local Unix socket. It ignores user option and login files.
- It creates one development database, one testing database, and one localhost application user for this checkout.
- The user password is `harness`. Use it only in disposable development containers.
- `AI_HARNESS_MYSQL_SOCKET` selects a different absolute socket path. The harness also writes this socket into the Laravel configuration.

## Cloud cleanup

`./.ai-harness cloud cleanup`:

- Requires a recorded ownership.
- Keeps the development database.
- Drops only the exact testing database and its numeric worker forms, `<testing>_1` and `<testing>_test_1`.
- Does not drop databases with similar names, backups, or databases of other checkouts.
- Keeps the ownership record, so that you can run cleanup again after a failure.

## Validate each cloud environment

Do not hide required install failures. A local test pass does not prove that the provider image, network allowlist, or cache work. In each cloud environment, test:

- A fresh start.
- A start from cache.
- A resumed session.

After you upgrade the package, run `./.ai-harness update` to refresh `.ai-harness-cloud`.
