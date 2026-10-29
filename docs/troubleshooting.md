# Troubleshooting

Start with `doctor`:

```bash
./.ai-harness doctor
```

Each `FAIL` line tells you what is wrong. This page gives the fix for each message.

## `doctor` failures

| Message | Fix |
| --- | --- |
| `.ai-harness bootstrap is missing or not executable` | Run `./vendor/bin/ai-harness update`. Make sure that Git keeps the executable bit (`git update-index --chmod=+x .ai-harness`). |
| `Laravel artisan entrypoint is missing` | Run the command in the root of a Laravel application, or use `--path`. |
| `No project configuration file exists; run init` | Run `./vendor/bin/ai-harness init`. |
| `Automatic Composer refresh hooks are missing; run ./.ai-harness update` | Run `./.ai-harness update`. |
| `Native PHP is unavailable` | Install PHP 8.2 or newer and add it to `PATH`, or change `runtime`. |
| `Laravel Herd is unavailable` | Install Herd and make sure that `herd` is on `PATH`, or change `runtime`. |
| Yerd daemon or JSON unavailable | Start Yerd 2.x and check `yerd --json status`. In Codex, run Yerd commands outside the sandbox. |
| Yerd PHP shim is missing | Install the selected version using `yerd install php <version>`; do not change global PHP. |
| Yerd Composer script missing | Install managed Composer with `yerd install tool composer`, or put a PHP Composer script on PATH. |
| Yerd site unowned or reassigned | Preserve the existing site. Check its JSON target and harness state; use a distinct checkout path. |
| `Laravel Valet is unavailable` | Install Valet and make sure that `valet` is on `PATH`, or change `runtime`. |
| `Laravel Sail is missing or not executable` | Install Sail with the host Composer: `composer require laravel/sail --dev`. Do not use `./.ai-harness composer`, because with `runtime=sail` it needs Sail. Or change `runtime`. |
| `Sail usage inferred from runtime/sail_services requires vendor/bin/sail` | Install Sail using host Composer: `composer require laravel/sail --dev`. With a host PHP runtime, `./.ai-harness composer require laravel/sail --dev` also works; or choose a host PHP runtime with an empty `sail_services` list. |
| `Cloud mysql is missing; run cloud provision` | Run `./.ai-harness-cloud provision`. |
| `Cloud redis-cli is missing; run cloud provision` | Run `./.ai-harness-cloud provision`. |
| `Agent instructions are missing` | Run `./.ai-harness update`. |
| `Codex local environment is missing` | Run `./.ai-harness update`. |
| `Claude instructions in CLAUDE.md shadow AGENTS.md` | Add the line `@AGENTS.md` to `CLAUDE.md`, or configure Claude to read both files. |
| `Claude hooks are missing` | Run `./.ai-harness update`. |
| `COMPOSER_AUTH is not valid: ...` | Correct the JSON in `COMPOSER_AUTH`. The message names the type and host. Refer to [Private packages](private-packages.md). |
| `COMPOSER_AUTH is set but the Sail compose file does not forward it` | Add `COMPOSER_AUTH: '${COMPOSER_AUTH:-}'` to the `laravel.test` environment, then run `./.ai-harness up`. Refer to [Laravel Sail](private-packages.md#laravel-sail). |

## Configuration errors

The harness stops when the configuration is not valid. Examples:

| Error | Cause |
| --- | --- |
| `Unknown configuration key [...]` | A key is misspelled or not supported. Refer to [All keys](configuration.md#all-keys). |
| `Duplicate configuration key [...]` | The same key occurs two times in one file. |
| `Unknown configuration key [service_manager]` | Remove the key from every configuration layer; see [migration](upgrading.md#remove-service_manager). |
| `cloud_seed requires cloud_migrate=true` | Set `cloud_migrate=true`, or set `cloud_seed=false`. |
| `php_version must be empty or a major.minor version` | Use a value such as `8.4`, not `php@8.4`. |
| `AI_HARNESS_ENV must be local, claude-cloud, or codex-cloud.` | Correct the environment variable. |

## Common problems

### New init options are unknown

Run `ai-harness init --help` using the same executable the agent uses. A source checkout with new options does not update an older Composer-global installation. Install/update the required package version, then rerun `init` with the [documented parameters](commands.md#configure-agents-with-parameters). Project installs use `./vendor/bin/ai-harness` or `./.ai-harness`; global installs use `ai-harness` from the global Composer bin directory.

### Parameter values or combinations are rejected

Boolean settings require explicit values, such as `--worktrees=false`; local `--migrate` and `--build` are bare flags. Use either `--agents=codex,claude` or repeated `--agent` flags, and only one of `--database` / `--db-connection`. Local assignments require uppercase `NAME=VALUE`, can contain further `=` characters, and cannot repeat the same name. Quote literal values so your shell does not expand them:

```bash
ai-harness init --global --agents=codex \
  --local-env='CUSTOM_FLAG=literal $HOME $(not-a-command) = value'
```

`--sail-services=mysql` enables Sail MySQL for any runtime. `--cloud-seed=true` requires `--cloud-migrate=true`. `--migrate` and `--build` on `init` require a selected Codex agent and `--worktrees=true`. These validation failures occur before project integration files are generated.

### Agent parameters seem to disappear or file changes are ignored

Generated values apply to the selected agent's command environment, not every terminal on the machine. Start a new session after changing agent settings, and select **Laravel AI Harness (Codex)** for parameter-generated Codex worktrees. Run `doctor` from that same session and inspect effective sources. If sources show built-in/file defaults, inspect the session's settings and shell filters; the Codex filters must allow `AI_HARNESS_*` values.

Existing exported settings take precedence over harness files. To change a selection from that session, rerun `init` with explicit parameters. Ordinary `update` preserves parameter-generated Codex lifecycle files. Omitted scalar parameters keep the current effective values; omitted local `--migrate` and `--build` flags disable those steps when regenerating. See [selection scope and clearing](configuration.md#where-parameter-selections-apply).

### Codex settings cannot be merged

The generator supports an explicit `[shell_environment_policy.set]` table with single-line harness string values. It refuses inline/dotted shell tables, duplicate tables, multiline TOML strings, and symlinks rather than attempting an ambiguous edit. Convert shell settings to the explicit table and move unrelated multiline values to another config layer before retrying. Unrelated supported keys and sections are preserved.

Global generation also refuses a tracked personal `.codex/config.toml`. Use a shared project installation if those settings should be tracked, or resolve the personal/shared file choice yourself; the harness does not remove files from the index.

### A local build fails before completion

`setup --build` requires a committed `package-lock.json`, then runs `npm ci --include=dev` and `npm run build`. Fix the reported npm/build failure and rerun setup. Migrations and frontend commands stop at the first nonzero exit; the presence of generated configuration alone does not mean setup completed.

### MySQL port 3306 is already in use

Another MySQL server uses the port. For Sail-managed MySQL, set a different forwarded port in `.ai-harness.config.local`, then run `./.ai-harness setup` again:

```ini
local_env.FORWARD_DB_PORT=3307
```

For an existing database outside Sail management, use `local_env.DB_PORT=3307`. Primary-checkout overrides are inherited by local worktrees. Different simultaneously running Sail stacks need different forwarded ports.

### A local override has no effect or setup reports a conflict

1. Run `./.ai-harness setup` after editing `.ai-harness.config.local`; `update` and runtime commands do not apply the values to `.env`.
2. Run `./.ai-harness doctor` to see each effective override's source file. Every value is redacted.
3. Clear inherited database URL/socket aliases with `local_env.DB_URL=`, `local_env.DATABASE_URL=`, and `local_env.DB_SOCKET=` when setting a host endpoint. For Redis endpoints, clear a conflicting `REDIS_URL` with `local_env.REDIS_URL=`.
4. Remove conflicting system-level variables from your shell or service environment. They take precedence over `.env` even when an alias is explicitly cleared there.
5. Use the default `bootstrap/cache/config.php` cache path. Setup clears that cache when applying overrides and refuses non-default `APP_CONFIG_CACHE` paths.
6. If tests use another endpoint, check `.env.testing` and forced PHPUnit XML values. Development overrides do not replace testing defaults.

Connection changes through `local_env.DB_CONNECTION` also check URL and socket aliases. Duplicate alias entries are all checked; explicitly clearing an alias removes its duplicates. For managed Sail MySQL, remove conflicting generated `DB_*` values from the process environment as well. Always unset a process-level `DB_DATABASE`, including the development database name, so tests can use their separate database. These managed checks run even without `local_env` settings.

See [Local application environment overrides](configuration.md#local-application-environment-overrides) for the full rules. Overrides that conflict with generated database names, managed Sail connections, or Herd/Valet URLs are configuration errors.

### The harness does not create MySQL databases

With `db_connection=auto`, the harness manages Sail databases when `mysql` is explicitly listed in `sail_services`, and detects existing MySQL/MariaDB servers from the application environment for host PHP. With `runtime=sail`, an empty list starts the full stack; add `db_connection=mysql` to enable isolated MySQL databases. An empty list with a host PHP runtime starts no containers. For an existing local MySQL/MariaDB service outside Sail, select `--database=mysql` or `db_connection=mysql`. See [Sail MySQL](local-environments.md#mysql-with-sail) and [host MySQL](local-environments.md#existing-host-mysql-or-mariadb).

### Host MySQL setup or cleanup fails

Check that the existing server is running, the configured port/socket and credentials are correct, and the executing PHP has `pdo_mysql`. Use an existing administrative account with the provisioning and grant permissions described in [database configuration](configuration.md#databases-on-an-existing-mysql--mariadb-server). Unset process `DB_*`, `DATABASE_URL`, and `MYSQL_ATTR_SSL_CA` values; clear conflicting dotenv URL aliases and use the default configuration-cache path. The harness never starts the host service or creates database users.

A refusal to adopt existing databases means those names exist without an ownership record. Do not delete them or fabricate state; identify their owner before retrying. A checkout/endpoint ownership mismatch requires restoring the original path and connection. Failed SQL retains ownership so `cleanup` can be retried. The password is not stored in ownership state.

### Parallel databases remain after teardown

Local cleanup matches numeric suffixes only: `<testing>_1` and `<testing>_test_2` are owned worker candidates; backups and other checkouts are deliberately preserved. Keep `.ai-harness.state.json` until cleanup finishes and run cleanup before deleting the worktree.

If cleanup reports `Skipping MySQL cleanup because Laravel Sail is unavailable`, database ownership is retained even if site removal succeeds. Restore the project's Sail executable/dependencies and rerun cleanup; do not treat that message as completed database teardown. For a nonzero cleanup exit, the generated `cleanup && down` sequence skips `down`, keeping Sail available for a retry. Cloud cleanup preserves development databases; local cleanup removes owned development and testing databases. See [cleanup and retries](local-environments.md#cleanup-and-retries).

### Herd commands fail with exit code 127

Herd can print PHP warnings when it looks up the PHP version of the site. Older harness versions then used the warning text as the program name. Update the package. The harness now finds the PHP binary through the Herd PHAR. Refer to [PHP version of the Herd site](local-environments.md#php-version-of-the-herd-site).

### Herd sites stay after you delete a worktree

Run `./.ai-harness prune-herd --dry-run` to see them. Then run `./.ai-harness prune-herd` to remove them. Refer to [Remove orphaned Herd sites](local-environments.md#remove-orphaned-herd-sites).

### Valet sites stay after you delete a worktree

Run `./.ai-harness prune-valet --dry-run` to see them. Then run `./.ai-harness prune-valet` to remove them. Refer to [Remove orphaned Valet sites](local-environments.md#remove-orphaned-valet-sites).

### A Herd or Valet command fails in Codex

Codex runs commands in a sandbox. Herd and Valet commands must run outside the sandbox. Approve the escalation request for the command.

### A Valet command asks for a password

Valet runs `link`, `secure`, `unsecure`, `unlink`, and `isolate` with `sudo`. An agent cannot type a password. Run `valet trust` one time. After that, Valet does not ask for a password.

### Composer hooks fail after an upgrade

Disable the scripts and refresh manually:

```bash
composer update mrkoopie/laravel-ai-harness --no-scripts
./vendor/bin/ai-harness update
```

For an upgrade from 0.1, refer to [Upgrading](upgrading.md#manual-upgrade).

### `cleanup` says that it cannot clean up without dependencies

`cleanup` needs `vendor/`. Run `composer install`, then run `cleanup` again. The ownership records stay. The next `cleanup` can then remove the resources.

### Cloud setup fails with `requires a committed composer.lock`

Commit `composer.lock` to the repository.

### Cloud setup uses the wrong PHP version

The image probably puts phpenv shims first in `PATH`. Add `export PATH="/usr/bin:$PATH"` to the setup and maintenance scripts. Refer to [phpenv shims](cloud.md#phpenv-shims).

### Cloud provisioning fails with `503 Service Unavailable` from Launchpad

The ondrej/php PPA on Launchpad often answers `503`. Set `AI_HARNESS_PHP_VERSION`, and add `packages.sury.org` to the network allowlist. Provisioning then uses the sury repository and leaves the PPA entries of the selected sources out. Refer to [PHP repository](cloud.md#php-repository).

### Cloud archive downloads are blocked

Set `AI_HARNESS_COMPOSER_PREFER=source` to install packages from Git. Refer to [Environment variables](cloud.md#environment-variables).
