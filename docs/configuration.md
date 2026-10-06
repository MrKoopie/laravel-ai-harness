# Configuration

The harness reads its settings from simple `key=value` files. Linked local worktrees also inherit the primary checkout's personal configuration.

## Configuration files

The harness loads these files in sequence. A later file overrides an earlier file.

| Order | File | Purpose | Commit it? |
| --- | --- | --- | --- |
| 1 | `.ai-harness.config.dist` | Shared defaults, for example in a template repository. | Yes |
| 2 | `.ai-harness.config` | The project configuration. `init` creates it. | Yes |
| 3 | Primary checkout's `.ai-harness.config.local` | Personal defaults inherited by linked local worktrees. | No |
| 4 | Current checkout's `.ai-harness.config.local` | Personal overrides for this checkout. | No, Git ignores it |

Each file can contain some or all keys. A key that is not in a file keeps its value from the earlier file or the default.

The primary file is loaded only for a registered Git worktree, and only locally. The harness reads the worktree's `.git` pointer, `commondir`, and registration back-pointer; it does not run Git. Ordinary checkouts read their local file once. Repositories with a bare or separate Git directory have no inherited primary file; put overrides in each checkout's own local file instead. Cloud execution does not inherit the primary file.

Example: the team uses Sail, but you use Herd for PHP. Put this in `.ai-harness.config.local`:

```ini
runtime=herd
```

## File format

```ini
# A comment starts with # or ;
runtime=herd
services=sail
sail_services=mysql,redis
herd_php="8.4"
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

For an existing database on your computer, with PHP running through native, Herd, or Valet and without harness-managed Sail MySQL:

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
services=sail
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
2. Herd and Valet own `APP_URL`. With those runtimes, a conflicting override is an error.
3. When `services=sail` and `sail_services` explicitly includes `mysql`, the harness owns `DB_*`, `DATABASE_URL`, and `MYSQL_ATTR_SSL_CA`. Use `FORWARD_DB_PORT` for the host port. Host PHP uses `127.0.0.1:<forwarded-port>`; PHP inside Sail uses `mysql:3306`. The development and testing databases keep separate generated names. Setup clears inherited database URLs, sockets, and SSL CA settings in both files so they cannot redirect the managed connection.
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
| `runtime` | `native` | `native`, `herd`, `valet`, `sail` | Where PHP, Artisan, Composer, and npm run. |
| `services` | `none` | `none`, `sail` | Use Sail to manage supporting containers. |
| `sail_services` | (empty) | List of Sail service names | Sail services to start. Empty means the full stack. Only valid with `services=sail`. |
| `herd_secure` | `true` | Boolean | Serve the Herd site with HTTPS. |
| `herd_php` | (empty) | Empty or `major.minor`, for example `8.4` | Isolate the Herd site to this PHP version. Empty uses the Herd default. |
| `valet_secure` | `true` | Boolean | Serve the Valet site with HTTPS. |
| `valet_php` | (empty) | Empty or `major.minor`, for example `8.4` | Isolate the Valet site to this PHP version (`php@8.4`). Empty uses the global Valet version. |

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
| `valet` | `valet php --site=<site> artisan` | `valet php --site=<site> artisan test` | `valet composer --site=<site>` | `valet php --site=<site>` | `npm` |
| `sail` | `sail artisan` | `sail artisan test` | `sail composer` | `sail php` | `sail npm` |

With `runtime=herd`, the harness runs PHP through the PHP binary of the Herd site when it can find it. Refer to [PHP version of the Herd site](local-environments.md#php-version-of-the-herd-site).

With `runtime=valet`, the harness gives the site of the checkout to `valet php` and `valet composer`. When the first argument is `--site=...`, the harness uses your site instead.

There is no automatic fallback to a different runtime. `doctor` tells you when the configured runtime is not available.

In a cloud environment, the harness always uses `native`. The `runtime` value is then ignored.

## Services

`services=sail` lets Sail manage the supporting containers. This is independent of the PHP runtime. You can use it for:

- A full Sail project (`runtime=sail`).
- Herd, Valet, or native PHP with MySQL, Redis, or Mailpit from Sail.

With an empty `sail_services`, `up` starts the full stack and `down` runs `sail down`. With a list, `up` starts only those services and `down` runs `sail stop` for them.

With `runtime=sail`, the harness always includes the Sail `laravel.test` application container, also when `services=none`.

The harness never deletes Docker volumes.

## Example setups

### Native PHP with SQLite

```ini
runtime=native
services=none
```

### Laravel Herd with SQLite

```ini
runtime=herd
services=none
herd_secure=true
herd_php=8.4
```

### Laravel Valet with SQLite

```ini
runtime=valet
services=none
valet_secure=true
valet_php=8.4
```

### Laravel Herd with MySQL and Redis from Sail

```ini
runtime=herd
services=sail
sail_services=mysql,redis,mailpit
herd_secure=true
herd_php=8.4
```

When another MySQL server already uses port 3306, set `local_env.FORWARD_DB_PORT=3307` in `.ai-harness.config.local` and run `setup`. Refer to [Local application environment overrides](#local-application-environment-overrides).

### Full Sail project

```ini
runtime=sail
services=sail
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
