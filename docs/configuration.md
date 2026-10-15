# Configuration

The harness reads its settings from simple `key=value` files. Linked local worktrees also inherit the primary checkout's personal configuration.

## Configuration files

The harness loads these files in sequence. A later file overrides an earlier file.

| Order | File | Purpose | Commit it? |
| --- | --- | --- | --- |
| 1 | `.ai-harness.config.dist` | Shared defaults, for example in a template repository. | Yes |
| 2 | `.ai-harness.config` | The project configuration. `init` creates it. | Yes |
| 3 | Primary checkout's `.ai-harness.config.global` | Global-install defaults inherited by linked local worktrees. | No |
| 4 | Current checkout's `.ai-harness.config.global` | Personal settings and mode marker created by `init --global`. Supports all keys. | No, Git ignores it |
| 5 | Primary checkout's `.ai-harness.config.local` | Personal defaults inherited by linked local worktrees. | No |
| 6 | Current checkout's `.ai-harness.config.local` | Personal overrides for this checkout. | No, Git ignores it |

Each file can contain some or all keys. A key that is not in a file keeps its value from the earlier file or the default.

Nested applications inherit personal files from the same relative application directory in the primary checkout. Discovery stops at an independent nested repository and never follows a corresponding primary application symlink outside that checkout.

The primary file is loaded only for a registered Git worktree, and only locally. The harness reads the worktree's `.git` pointer, `commondir`, and registration back-pointer; it does not run Git. Ordinary checkouts read their local file once. Repositories with a bare or separate Git directory have no inherited primary file; put overrides in each checkout's own local file instead. Cloud execution does not inherit the primary file.

Example: the team uses Sail, but you use Herd for PHP. Put this in `.ai-harness.config.local`:

```ini
runtime=herd
```

## Environment-variable overrides

Precedence is built-in defaults → the configuration files above → exported process variables:

| Variable | Configuration key |
| --- | --- |
| `AI_HARNESS_RUNTIME` | `runtime` |
| `AI_HARNESS_PHP_VERSION` | `php_version` |
| `AI_HARNESS_SITE_SECURE` | `site_secure` |
| `AI_HARNESS_SERVICE_MANAGER` | `service_manager` |

All commands that resolve configuration use these overrides, including `init`, `setup`, `doctor`, `php`, `artisan`, `composer`, `test`, `up`, `down`, and `cleanup`. Unset variables preserve file/default behavior. Values use the same validation as their configuration keys: an explicitly empty PHP version clears a file pin for command resolution (it does not remove an existing site pin); empty runtime, service manager, and HTTPS values are errors. PHP accepts any `major.minor` version allowed by the existing validator. Cross-setting rules still apply locally, such as `sail_services` requiring `service_manager=sail`; cloud execution uses `cloud_services` instead.

No configuration file is required when process variables supply settings. `doctor` accepts this configuration and reports the effective runtime, PHP version, HTTPS, and service manager with their source variable, file, or built-in default. Other doctor checks still require the selected tools and agent integrations to be installed.

### Codex environment example

For a Codex local environment with Yerd installed and running, and PHP 8.4 already installed (the consuming application requires PHP `^8.4.1`):

```bash
export AI_HARNESS_RUNTIME=yerd
export AI_HARNESS_PHP_VERSION=8.4
export AI_HARNESS_SITE_SECURE=true
export AI_HARNESS_SERVICE_MANAGER=none

ai-harness init --global
ai-harness setup
ai-harness doctor
ai-harness test
```

Make all four exports available in the environment used by **every later harness command**, including agent commands and cleanup. Configure them in the Codex environment or shell initialization used by those commands; if a fresh shell does not inherit them, re-export the same values there. Setting variables only in a setup script, or prefixing only `setup` with them, does not configure subsequent commands. Generated agent instructions tell future AI work to preserve or re-export these values.

`init --global` still creates its normal personal configuration/mode marker, containing defaults rather than a snapshot of the exports. Overrides are never saved to harness configuration files or Laravel's `.env`. Setup still writes generated application values such as `APP_URL` and managed database connections normally. Hostnames and isolated database names remain automatic for each checkout; do not supply your own. Cleanup checks recorded ownership even if the current runtime overrides have changed.

`AI_HARNESS_ENV` retains its existing behavior: `local` selects local execution, while detected Claude/Codex cloud execution uses native PHP and cloud services regardless of these local runtime settings. These configuration overrides do not install local PHP versions. `AI_HARNESS_PHP_VERSION` also retains its existing [cloud provisioning role](cloud.md#ai_harness_php_version): `cloud provision` uses it to select and install PHP packages. The other three variables do not change cloud provisioning.

## File format

```ini
# A comment starts with # or ;
runtime=herd
service_manager=sail
sail_services=mysql,redis
php_version="8.4"
```

Rules:

- Each line is `key=value`. Spaces around the key and value are removed.
- Empty lines and lines that start with `#` or `;` are comments.
- A value can have single or double quotes. The quotes must be balanced.
- A list is comma-separated, for example `mysql,redis`. An empty list item is an error.
- A boolean is `true`, `false`, `1`, `0`, `yes`, `no`, `on`, or `off`.
- Each key can occur only one time in each file.
- A file must be 64 KiB or smaller. A value must be 4096 bytes or smaller.

The harness reads these files as data. It never runs them as shell scripts. An unknown key, an invalid value, or a duplicate key stops the command with a clear error.

After you change `agents`, `worktrees`, or `cloud`, run `./.ai-harness update`. This refreshes the managed agent files.

## Local application environment overrides

Use `local_env.<VARIABLE>=<value>` for settings that differ on your computer. Put personal values in `.ai-harness.config.local` in your primary checkout so new worktrees inherit them. Variable names must use uppercase letters, digits, or underscores, and cannot start with a digit.

For an existing database on your computer, with PHP running through native, Herd, Valet, or Yerd and without harness-managed Sail MySQL:

```ini
local_env.DB_HOST=127.0.0.1
local_env.DB_PORT=3307
local_env.REDIS_PORT=6380
local_env.MAIL_PORT=1026
local_env.CUSTOM_FEATURE_ENABLED=false
```

For MySQL managed by Sail, set the forwarded port instead:

```ini
runtime=herd
service_manager=sail
sail_services=mysql
local_env.FORWARD_DB_PORT=3307
```

Then run:

```bash
./.ai-harness setup
./.ai-harness doctor
```

`setup` applies the effective overrides to ordinary `.env` entries before Composer installation, service startup, or Artisan commands. It requires `.env` or `.env.example`, and copies `.env.example` only when `.env` is missing. It replaces only explicitly configured entries, preserves unrelated values, and reapplies overrides on each setup. `.env.example` is unchanged. `update`, `up`, and runtime commands do not apply environment overrides; run `setup` after editing them.

Overrides merge per variable. A worktree can change just one inherited setting:

```ini
# This worktree's .ai-harness.config.local
local_env.FORWARD_DB_PORT=3308
```

Different Sail stacks running together need different forwarded ports. Redis and mail overrides only set application values; they do not automatically change service configuration. Forwarded ports work when the project's Compose file uses the corresponding variable.

The namespace is accepted in all configuration layers, so teams can also put non-secret local defaults in `.ai-harness.config` or `.dist`. Keep credentials in the Git-ignored local file. Values follow the harness file format above; quotes delimit a literal value, and shell commands or variable references are not evaluated. Control characters are rejected. A value cannot contain both backticks and apostrophes because it cannot be represented safely for both Laravel's dotenv parser and Sail's Bash reader. An empty value explicitly clears the entry. Removing an override leaves the last written `.env` value in place; edit `.env` if you want to restore it. Port overrides for MySQL, Redis, mail, Mailpit, the application, and Vite must be integers from 1 to 65535.

### Managed values and testing

1. `APP_KEY`, `APP_ENV`, and `APP_CONFIG_CACHE` cannot be overridden through `local_env`.
2. Herd, Valet, and Yerd own `APP_URL`. With those runtimes, a conflicting override is an error.
3. When `service_manager=sail` and `sail_services` explicitly includes `mysql`, the harness owns `DB_*`, `DATABASE_URL`, and `MYSQL_ATTR_SSL_CA`. Use `FORWARD_DB_PORT` for the host port. Host PHP uses `127.0.0.1:<forwarded-port>`; PHP inside Sail uses `mysql:3306`. The development and testing databases keep separate generated names. Setup clears inherited database URLs, sockets, and SSL CA settings in both files so they cannot redirect the managed connection.
4. Overrides target `.env`. A newly created `.env.testing` starts from `.env`, then receives the harness's testing defaults: isolated SQLite, array mail/cache/session drivers, and a synchronous queue. Database URL and socket aliases are cleared. Existing testing files are preserved, except for managed Sail MySQL values. Changing a development override does not rewrite unrelated settings in an existing `.env.testing`.
5. `local_env.*` values are ignored in cloud execution, including custom application variables. The existing cloud configuration remains independent.

### Inspecting conflicts

`doctor` lists the source file of each effective override and redacts every value, including custom variables. It flags cached Laravel configuration, connection URLs or sockets that would take precedence over endpoint settings, conflicting process environment variables, and non-default `APP_CONFIG_CACHE` paths.

Changing `DB_CONNECTION` also checks inherited database URLs and sockets. Every duplicate alias entry is inspected: any nonempty entry is a conflict unless an explicit override replaces all entries for that name. This prevents different dotenv and shell readers from choosing different connections.

For managed Sail MySQL, setup and `doctor` also check all generated connection values against the process environment, even when no `local_env` settings are configured. Matching host, port, connection, and credential values are accepted. Any process-level `DB_DATABASE` must be unset: even the correct development name would replace the separate testing database. The expected host and port reflect whether PHP runs on the host or inside Sail, and the forwarded port is checked separately.

`setup` clears `bootstrap/cache/config.php` when overrides are applied. It refuses connection and process conflicts before changing files or starting commands. Remove a conflicting variable from your shell, or explicitly clear an inherited `.env` alias:

```ini
local_env.DB_PORT=3307
local_env.DB_URL=
local_env.DATABASE_URL=
local_env.DB_SOCKET=
```

Explicit clears cannot override conflicting system-level environment variables; remove those from the process environment. Use the default configuration cache path when applying local overrides. If `phpunit.xml` forces database settings, those are independent of `.env.testing`; outside managed Sail MySQL, adjust them yourself.

## All keys

### Local runtime

| Key | Default | Values | Description |
| --- | --- | --- | --- |
| `runtime` | `native` | `native`, `herd`, `valet`, `yerd`, `sail` | Where PHP, Artisan, Composer, and npm run. |
| `service_manager` | `none` | `none`, `sail` | Use Sail to manage supporting containers. |
| `sail_services` | (empty) | List of Sail service names | Sail services to start. Empty means the full stack. Only valid with `service_manager=sail`. |
| `site_secure` | `true` | Boolean | Enable HTTPS for the selected Herd, Valet, or Yerd site and its `APP_URL`. Ignored by native, Sail, and cloud execution. |
| `php_version` | (empty) | Empty or `major.minor`, for example `8.4` | Select the PHP version for a Herd, Valet, or Yerd site. Empty preserves the existing selection. Ignored by native, Sail, and cloud execution. |

The site settings follow `runtime`, so switching between Herd, Valet, and Yerd does not require new keys. Run `setup` after changing them. An empty `php_version` does not remove an existing site pin. Valet can install missing PHP through Homebrew; Herd and Yerd require the requested version to be installed. Native PHP uses PATH, Sail uses its image, and cloud uses the provisioned PHP.

### Agents

| Key | Default | Values | Description |
| --- | --- | --- | --- |
| `agents` | `codex,claude` | List of `codex`, `claude` | The coding agents to integrate. An empty value disables the agent files. |
| `worktrees` | `true` | Boolean | Write the Codex local environment and the Claude worktree hooks. |

### Cloud

The `cloud_*` keys apply only in a detected cloud environment. They never change local setup. The `cloud` key also controls which managed files `init` and `update` write locally. Refer to [Cloud environments](cloud.md).

| Key | Default | Values | Description |
| --- | --- | --- | --- |
| `cloud` | `true` | Boolean | Write `.ai-harness-cloud` and the Claude cloud session hooks. |
| `cloud_services` | `mysql` | List of `mysql`, `redis` | Local services in the cloud container. Empty means SQLite and no services. |
| `cloud_migrate` | `false` | Boolean | Run `artisan migrate` for the development and testing databases during setup. |
| `cloud_seed` | `false` | Boolean | Run the development seeder on each setup. Requires `cloud_migrate=true`. |
| `cloud_build` | `false` | Boolean | Run `npm run build` during setup. |
| `cloud_browser` | `false` | Boolean | Install Playwright Chromium and its OS dependencies. |

## Runtimes

`runtime` selects where the runtime commands execute:

| Runtime | `artisan` | `test` | `composer` | `php` | `npm` |
| --- | --- | --- | --- | --- | --- |
| `native` | `php artisan` | `php artisan test` | `composer` | `php` | `npm` |
| `herd` | `herd php artisan` | `herd php artisan test` | `herd composer` | `herd php` | `npm` |
| `yerd` | `php<version> artisan` | `php<version> artisan test` | `php<version> composer.phar` | `php<version>` | `npm` |
| `valet` | `valet php --site=<site> artisan` | `valet php --site=<site> artisan test` | `valet composer --site=<site>` | `valet php --site=<site>` | `npm` |
| `sail` | `sail artisan` | `sail artisan test` | `sail composer` | `sail php` | `sail npm` |

With `runtime=herd`, the harness runs PHP through the PHP binary of the Herd site when it can find it. Refer to [PHP version of the Herd site](local-environments.md#php-version-of-the-herd-site).

With `runtime=valet`, the harness gives the site of the checkout to `valet php` and `valet composer`. When the first argument is `--site=...`, the harness uses your site instead.

There is no automatic fallback to a different runtime. `doctor` tells you when the configured runtime is not available.

In a cloud environment, the harness always uses `native`. The `runtime` value is then ignored.

## Services

`service_manager=sail` lets Sail manage the supporting containers. This is independent of the PHP runtime. You can use it for:

- A full Sail project (`runtime=sail`).
- Herd, Valet, Yerd, or native PHP with MySQL, Redis, or Mailpit from Sail.

With an empty `sail_services`, `up` starts the full stack and `down` runs `sail down`. With a list, `up` starts only those services and `down` runs `sail stop` for them.

With `runtime=sail`, the harness always includes the Sail `laravel.test` application container, also when `service_manager=none`.

The harness never deletes Docker volumes.

## Example setups

### Native PHP with SQLite

```ini
runtime=native
service_manager=none
```

### Laravel Herd with SQLite

```ini
runtime=herd
service_manager=none
site_secure=true
php_version=8.4
```

### Laravel Valet with SQLite

```ini
runtime=valet
service_manager=none
site_secure=true
php_version=8.4
```

### Laravel Herd with MySQL and Redis from Sail

```ini
runtime=herd
service_manager=sail
sail_services=mysql,redis,mailpit
site_secure=true
php_version=8.4
```

When another MySQL server already uses port 3306, set `local_env.FORWARD_DB_PORT=3307` in `.ai-harness.config.local` and run `setup`. Refer to [Local application environment overrides](#local-application-environment-overrides).

### Full Sail project

```ini
runtime=sail
service_manager=sail
sail_services=
```

### Claude Code only, without worktree hooks

```ini
agents=claude
worktrees=false
```

### Cloud with MySQL, Redis, and migrations

```ini
cloud=true
cloud_services=mysql,redis
cloud_migrate=true
cloud_build=true
```
