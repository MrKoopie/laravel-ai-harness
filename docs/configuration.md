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

Precedence is built-in defaults → the configuration files above → exported process variables → explicit `init` parameters:

| Variable | Configuration key |
| --- | --- |
| `AI_HARNESS_RUNTIME` | `runtime` |
| `AI_HARNESS_PHP_VERSION` | `php_version` |
| `AI_HARNESS_SITE_SECURE` | `site_secure` |
| `AI_HARNESS_DB_CONNECTION` | `db_connection` |
| `AI_HARNESS_DATABASE_PROVISION_USERNAME` | `database_provision_username` |
| `AI_HARNESS_DATABASE_PROVISION_PASSWORD` | `database_provision_password` |
| `AI_HARNESS_DATABASE_PARALLEL_PROCESSES` | `database_parallel_processes` |
| `AI_HARNESS_AGENTS` | `agents` |
| `AI_HARNESS_SAIL_SERVICES` | `sail_services` |
| `AI_HARNESS_WORKTREES` | `worktrees` |
| `AI_HARNESS_CLOUD` | `cloud` |
| `AI_HARNESS_CLOUD_SERVICES` | `cloud_services` |
| `AI_HARNESS_CLOUD_MIGRATE` | `cloud_migrate` |
| `AI_HARNESS_CLOUD_SEED` | `cloud_seed` |
| `AI_HARNESS_CLOUD_BUILD` | `cloud_build` |
| `AI_HARNESS_CLOUD_BROWSER` | `cloud_browser` |
| `AI_HARNESS_LOCAL_ENV` | JSON object of `local_env` string values |

All commands that resolve configuration use these overrides, including `init`, `setup`, `doctor`, `php`, `artisan`, `composer`, `test`, `up`, `down`, and `cleanup`. Unset variables preserve file/default behavior. Values use the same validation as their configuration keys: an explicitly empty PHP version clears a file pin for command resolution (it does not remove an existing site pin); empty runtime and HTTPS values are errors. PHP accepts any `major.minor` version allowed by the existing validator. Sail usage is inferred from `runtime` and `sail_services`; cloud execution uses `cloud_services` instead.

No configuration file is required when process variables supply settings. `doctor` accepts this configuration and reports the effective runtime, PHP version, HTTPS, Sail services, and database selection with their source variable, file, or built-in default. Other doctor checks still require the selected tools and agent integrations to be installed.

### Codex environment example

Generate a complete Codex local environment once, with Yerd running and PHP 8.5 already installed:

```bash
ai-harness init --global --agent=codex \
  --runtime=yerd --php-version=8.5 --database=mysql --migrate --build
```

`init` only writes files. Select **Laravel AI Harness (Codex)** in the app before creating a worktree. The generated setup script initializes the new checkout, prepares isolated databases and its site, runs development migrations, installs locked npm dependencies, and builds assets. Omit `--migrate` or `--build` to leave those steps out. Rerun the command to change the selections.

Every setting also has an [init parameter](commands.md#configure-agents-with-parameters), including `--sail-services`, repeatable `--local-env`, all `--cloud-*` keys, and `--agents=codex,claude`. No `--agent=codex` flag is required when Codex is already selected by the effective configuration.

The generator writes:

- `.codex/config.toml`: merges harness values into `[shell_environment_policy.set]` for later agent commands, preserving other keys and sections. Existing shell filters must allow `AI_HARNESS_*` variables.
- `.claude/settings.local.json` (when Claude is selected): merges the same selections into its `env` object, preserving other environment variables, permissions, and custom hooks. Shared mode uses `.claude/settings.json`.
- `.codex/environments/ai-harness-global.toml` (when Codex worktrees are enabled): exports the same selections in setup and cleanup. Cleanup runs before `down`, so database failure keeps the service available for a retry. Ordinary `init` and `update` preserve this configured environment.

Global mode excludes the generated files through `.git/info/exclude`. It refuses to modify a tracked personal Codex configuration; use a project installation when these settings should be shared. Without `--global`, the environment is `.codex/environments/environment.toml` and the generated scripts use `./.ai-harness`.

Configuration merging supports the explicit `[shell_environment_policy.set]` table and single-line harness string values. Ambiguous multiline strings, inline/dotted shell tables, and symlinks are refused before project files are generated. Move those shell settings to the explicit table before retrying. The generator does not change the running app's environment selection.

For manual integration, export the applicable variables from the table in every setup, cleanup, and agent command environment. Setup-only exports do not configure later shells. `AI_HARNESS_LOCAL_ENV` is a JSON object of uppercase variable names and string values, for example `'{"DB_PORT":"3307","CUSTOM_FLAG":""}'`. Values merge per variable with file settings; explicit `--local-env` parameters win over the JSON values. Malformed objects, invalid names, and non-string values are rejected. Local overrides stay ignored in cloud execution.

With `--agents=claude`, only Claude is configured. With both agents selected, both receive the full settings, including cloud and local application overrides. Claude uses the standard [settings env object](https://code.claude.com/docs/en/settings); fresh worktree integrations initialized by its hooks retain inherited harness settings. Global mode keeps these files ignored; shared agent files may be committed, so keep personal credentials in global mode or ignored local settings.

Runtime selections are not copied into harness configuration or Laravel `.env`. `init --global` still creates its ordinary defaults/mode marker. Setup writes generated application values such as `APP_URL` and isolated database connections normally. Site names and database names stay automatic; cleanup checks recorded ownership even after runtime selections change.

`AI_HARNESS_ENV` retains its existing behavior: `local` selects local execution, while detected Claude/Codex cloud execution uses native PHP and cloud services regardless of these local runtime settings. These configuration overrides do not install local PHP versions. `AI_HARNESS_PHP_VERSION` also retains its existing [cloud provisioning role](cloud.md#ai_harness_php_version): `cloud provision` uses it to select and install PHP packages. The other configuration overrides do not install PHP or manage the provisioner’s operating-system package sources.

## Where parameter selections apply

`init` resolves explicit parameters over the current process and file configuration, then saves the effective selections in each selected agent's command settings. It does not rewrite `.ai-harness.config*` with those selections. Codex setup and cleanup scripts export the same values, and Claude hooks retain inherited selections when initializing fresh worktrees. Generating settings does not change an already running shell or select an environment in the app.

An ordinary terminal does not load `.codex/config.toml` or `.claude/settings*.json` through the harness. Run commands in the configured agent session, export the equivalent `AI_HARNESS_*` values in the terminal, or deliberately use `.ai-harness.config.local` for file-based personal defaults. `doctor` reports the source used by the process that actually ran it; it does not prove the app selected a particular environment.

### Change or clear a selection

Rerun `init` with explicit new values; those parameters win even when the current agent still exports old selections. Omitted scalar parameters retain the effective value for the process running `init`, which may come from an existing agent environment. Use the [parameter table](commands.md#configure-agents-with-parameters) rather than editing generated setup scripts.

- Boolean settings take values such as `--cloud=false` and `--worktrees=false`.
- `--sail-services=` and `--cloud-services=` explicitly select empty lists. These mean the full Sail stack and cloud SQLite respectively.
- `--local-env=NAME=` sets an empty application value. It does not remove that override or restore an inherited value. To remove an override, remove its entry from the generated `AI_HARNESS_LOCAL_ENV` object (and any originating personal config) before regenerating; removing an override does not undo a value already applied to `.env`.
- `--php-version=` clears a configuration pin for command resolution but does not remove an existing site's PHP pin.
- Omit local `--migrate` and `--build` flags when regenerating to disable those steps. These are separate from the boolean cloud settings.
- `--agents=` disables the current integrations and writes no new agent command environment; it does not change the environment of an already running session.

When parameter-generated worktree automation is enabled, ordinary `init` and `update` preserve the Codex lifecycle file. To change that automation, use parameter-based `init` again. Agent settings contain the full resolved local override map, including inherited personal values; use global mode when those values should stay private.

The named parameters cover the keys in [All keys](#all-keys) and `local_env.*`. Provider execution detection (`AI_HARNESS_ENV`), cloud provisioner package-source/extension controls, and Composer authentication are separate execution inputs, not additional harness config keys. See [cloud environment variables](cloud.md#environment-variables) and [private packages](private-packages.md).

## File format

```ini
# A comment starts with # or ;
runtime=herd
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
3. When `sail_services` explicitly includes `mysql`, or `runtime=sail` uses an empty list with `db_connection=mysql`, the harness owns `DB_*`, `DATABASE_URL`, and `MYSQL_ATTR_SSL_CA`. Use `FORWARD_DB_PORT` for the host port. Host PHP uses `127.0.0.1:<forwarded-port>`; PHP inside Sail uses `mysql:3306`. The development and testing databases keep separate generated names. Setup clears inherited database URLs, sockets, and SSL CA settings in both files so they cannot redirect the managed connection.
4. Overrides target `.env`. A newly created `.env.testing` starts from `.env`, then receives the harness's testing defaults: isolated SQLite, array mail/cache/session drivers, and a synchronous queue. Database URL and socket aliases are cleared. Existing testing files are preserved, except when Sail MySQL or an explicit `db_connection` selection manages the testing connection. Changing a development override does not rewrite unrelated settings in an existing `.env.testing`.
5. Existing-server provisioning owns generated `DB_DATABASE` names. Driver and endpoint overrides such as `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, and credentials remain configurable; explicit `db_connection=mysql` or `mariadb` takes precedence over the application driver. See [host MySQL](local-environments.md#existing-host-mysql-or-mariadb). Explicit SQLite owns its connection and database name.
6. `local_env.*` values are ignored in cloud execution, including custom application variables. The existing cloud configuration remains independent.

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

Explicit clears cannot override conflicting system-level environment variables; remove those from the process environment. Use the default configuration cache path when applying local overrides. If `phpunit.xml` forces database settings, those are independent of `.env.testing`; outside managed Sail MySQL or explicit `db_connection` selection, adjust them yourself.

## All keys

### Local runtime

| Key | Default | Values | Description |
| --- | --- | --- | --- |
| `runtime` | `native` | `native`, `herd`, `valet`, `yerd`, `sail` | Where PHP, Artisan, Composer, and npm run. |
| `sail_services` | (empty) | List of Sail service names | A nonempty list enables Sail services for any runtime. Empty means the full stack for `runtime=sail`, and no containers for host PHP. |
| `site_secure` | `true` | Boolean | Enable HTTPS for the selected Herd, Valet, or Yerd site and its `APP_URL`. Ignored by native, Sail, and cloud execution. |
| `db_connection` | `auto` | `auto`, `sqlite`, `mysql`, `mariadb` | `auto` detects existing MySQL/MariaDB from the application environment. `mysql` uses managed Sail MySQL when configured, otherwise an existing host service. `sqlite` selects a checkout-local SQLite file. |
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

Sail usage follows `runtime` and `sail_services`; no separate service manager is needed.

| PHP runtime | `sail_services` | `up` | `down` |
| --- | --- | --- | --- |
| Native, Herd, Valet, or Yerd | Empty | No containers | No containers |
| Native, Herd, Valet, or Yerd | `mysql,redis` | `sail up -d mysql redis` | `sail stop mysql redis` |
| Sail | Empty | `sail up -d` (full stack) | `sail down` |
| Sail | `mysql,redis` | `sail up -d laravel.test mysql redis` | `sail stop laravel.test mysql redis` |

With `runtime=sail`, an explicit subset always includes the `laravel.test` application container. For isolated MySQL databases, list `mysql`, or use `db_connection=mysql` with the full Sail stack. An empty list with `db_connection=auto` leaves database configuration unchanged. Cloud execution uses `cloud_services` independently.

The harness never deletes Docker volumes.

## Example setups

### Native PHP with SQLite

```ini
runtime=native
```

### Laravel Herd with SQLite

```ini
runtime=herd
site_secure=true
php_version=8.4
```

### Laravel Valet with SQLite

```ini
runtime=valet
site_secure=true
php_version=8.4
```

### Laravel Herd with MySQL and Redis from Sail

```ini
runtime=herd
sail_services=mysql,redis,mailpit
site_secure=true
php_version=8.4
```

When another MySQL server already uses port 3306, set `local_env.FORWARD_DB_PORT=3307` in `.ai-harness.config.local` and run `setup`. Refer to [Local application environment overrides](#local-application-environment-overrides).

### Full Sail project

```ini
runtime=sail
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

## Databases on an existing MySQL / MariaDB server

Setup reads the application's normal `.env` settings. When `.env` does not exist, it reads `.env.example`, which setup copies into `.env`. `DB_CONNECTION=mysql` or `DB_CONNECTION=mariadb` automatically selects provisioning on that existing server. There is no database mode flag and no duplicate connection configuration. This works with native PHP, Yerd, Herd and Valet. Sail runtimes and full Sail stacks keep their existing database behavior, as does Sail's explicitly configured MySQL service; SQLite and other connections keep theirs. Cloud uses its own local services and preserves development data during cleanup.

Development and testing database names are generated by default from the checkout basename and path hash. Setup replaces the original `DB_DATABASE` value; worktree-specific names are never required. An explicit `AI_HARNESS_DB_DATABASE` override can select a custom development name when needed. The server address, port, socket, username and password come from the normal `DB_*` settings. Explicit `init --database=mysql` or `--database=mariadb` selects that driver even for a SQLite template; `--database=sqlite` disables existing-server provisioning. Defaults are `127.0.0.1:3306`, username `root` and an empty password. `DB_SOCKET` takes precedence over host/port. Quoted values, comments, exports and references to earlier dotenv variables use Laravel's dotenv parser, without executing code or modifying the process environment. `MYSQL_ATTR_SSL_CA` is preserved and also used by the provisioning connection when configured.

Personal overrides still use the existing `local_env.DB_*` configuration keys and primary-checkout inheritance. They override the dotenv values. Keep personal credentials in `.ai-harness.config.local` when they differ from the application defaults. `local_env.DB_DATABASE` is reserved for generated names; nonempty connection URL overrides cannot override managed connections. Setup clears stale `DB_URL` and `DATABASE_URL` entries in the environment files.

Only these optional provisioning settings are added to the harness configuration:

| Key | Default | Meaning |
| --- | --- | --- |
| `database_provision_username` | empty | Existing administrative account; empty reuses the application credentials from `.env` |
| `database_provision_password` | empty | Password for that administrative account; empty means an empty password |
| `database_parallel_processes` | `0` | Pre-provision Laravel parallel test tokens 1 through N; 0–64 |

### Process environment overrides

Process overrides take precedence over dotenv and personal `local_env.*` settings. Use harness-prefixed variables so Laravel does not inherit a development database name into the testing connection:

| Variable | Overrides |
| --- | --- |
| `AI_HARNESS_DB_USERNAME` | Application login (`DB_USERNAME`) |
| `AI_HARNESS_DB_PASSWORD` | Application password (`DB_PASSWORD`); an empty value is intentional |
| `AI_HARNESS_DB_HOST` | Server host (`DB_HOST`) |
| `AI_HARNESS_DB_PORT` | TCP port (`DB_PORT`) |
| `AI_HARNESS_DB_SOCKET` | Socket (`DB_SOCKET`); set empty to clear an inherited socket |
| `AI_HARNESS_DB_CONNECTION` | `auto`, `sqlite`, `mysql`, or `mariadb`; explicit SQL drivers can switch a SQLite template |
| `AI_HARNESS_DB_DATABASE` | Custom development database name; testing uses `<name>_testing` |

```bash
AI_HARNESS_DB_HOST=127.0.0.1 \
AI_HARNESS_DB_USERNAME=local_app \
AI_HARNESS_DB_PASSWORD="$LOCAL_DATABASE_PASSWORD" \
AI_HARNESS_DB_DATABASE=my_custom_database \
./.ai-harness setup
```

Only supplied overrides replace the normal connection settings. Passwords retain whitespace, literal values and empty strings. Custom database names must contain 1–48 letters, digits or underscores, leaving room for testing and parallel process suffixes; the harness registry name is reserved. Both custom and generated names require the same ownership verification: a pre-existing custom database is never adopted. A different checkout using the same custom name is refused.

Setup writes the effective application connection into `.env` and `.env.testing` and records the selected database pair in checkout ownership state. Later setup and cleanup can reuse it without repeating one-command overrides. Clean up the owned pair before selecting different names. Provisioning credentials stay in harness configuration. Doctor lists override variable names with values redacted. Standard process-level `DB_*` overrides remain conflicts because they would also override `.env.testing`.

These keys follow normal configuration precedence and primary-checkout inheritance. Keep administrative credentials in `.ai-harness.config.local`. The application account must already exist; setup connects without selecting the old application's database and discovers its account host using `CURRENT_USER()`. The harness never creates accounts or changes passwords. Separate administrative credentials let an application account with limited privileges use the generated databases.

The provisioning account needs `CREATE`, `DROP`, and authority to grant the common MySQL/MariaDB schema privileges to the application account, plus `SELECT`, `INSERT`, `UPDATE`, `DELETE`, `CREATE TABLE` and schema creation privileges for the registry. An administrative account with `ALL PRIVILEGES ON *.* WITH GRANT OPTION` meets these requirements; a separate application account does not need global privileges. `SHOW GRANTS FOR` the application account and `SELECT` on `mysql.db` must also be permitted; the latter records original exact-schema privileges before granting access. Doctor checks connectivity, stale credentials and SSL CA settings, and inspects grants without modifying databases or printing credential values. Restricted or role-derived grants can be reported as unverified and require manual review.

The PHP process launching the harness needs `pdo_mysql`; DOM (`ext-dom`) is also required when `phpunit.xml` or `phpunit.xml.dist` is present. Setup checks DOM before provisioning or changing files. Provisioning does not invoke the selected PHP runtime, application Artisan commands, Sail, or a MySQL CLI, and never starts or stops the server. Both the harness and application must be able to reach the configured endpoint. Conflicting process-level connection variables must be unset so that `.env.testing` can select its own database. Custom `APP_CONFIG_CACHE` paths must also be removed; setup clears the standard Laravel configuration cache.

Setup provisions databases and grants access before publishing database settings or clearing the Laravel configuration cache. Connection and privilege failures preserve existing `.env`, `.env.testing`, PHPUnit and cache contents. It then reconciles database settings in `.env` and `.env.testing`, preserving unrelated application and test settings. Dotenv credentials may contain both backticks and apostrophes when Sail is not sourcing the environment file; Sail retains its shell-safe encoding restrictions. It removes inline PHPUnit database overrides, including forced `<env>` and `<server>` entries, and ensures PHPUnit selects `.env.testing`. If only `phpunit.xml.dist` exists, it creates a local `phpunit.xml`.

Ownership uses `.ai-harness.state.json` plus matching random tokens and checkout identities in the shared server table `ai_harness_registry.databases`. Keep the registry private to provisioning accounts and preserve the local state file. The registry reserves each absent schema before creation and records the successful CREATE acknowledgement. Rejected CREATE statements and failed grants are retryable. If execution stops between CREATE and its acknowledgement record, the existing schema is ambiguous: setup and cleanup both refuse it until an administrator verifies ownership. A concurrent unrelated CREATE is never adopted. Existing schemas without matching ownership evidence are refused, even if their names match.

The shared `ai_harness_registry.schema_grants` table records application usernames, account hosts and original exact-schema privileges before each grant. Cleanup revokes only privileges added by the harness, including grants to earlier application accounts after credential changes, before dropping individually verified schemas. Pre-existing exact-schema and broader grants remain intact. Failed revocation, DROP or metadata deletion retains evidence for retry. Ownership records created before grant tracking was introduced have no verifiable privilege baseline, so cleanup does not revoke their unknown grants. Restore the original connection settings and administrative credentials before retrying. Copied ownership state or a different endpoint cannot authorize cleanup. Socket identity uses the socket path alone because host and port are ignored for that transport; matching legacy fingerprints migrate during setup. The shared registry schema, application account and database server stay available. A server advisory lock serializes cooperating harness commands; administrators must not replace schemas or change ownership records concurrently.

For parallel testing, set `database_parallel_processes` at least as high as `artisan test --parallel --processes=N`. Each exact `<testing_database>_test_<token>` schema is created, recorded and granted separately, including CREATE/DROP for `--recreate-databases`. No database wildcard is granted. Lowering the configured count retains previously recorded ownership for cleanup; unrelated matching schemas are never adopted or dropped. Existing broader application grants are not revoked.

### Complete Yerd + PHP 8.5 + existing MariaDB example

With MariaDB already running locally, keep the runtime configuration in `.ai-harness.config`:

```ini
runtime=yerd
php_version=8.5
site_secure=true
sail_services=
database_parallel_processes=4
```

Use the application's existing `.env` connection settings. If it is missing, setup copies `.env.example` first:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=harness_app
DB_PASSWORD="your-existing-application-password"
```

`DB_DATABASE=laravel` is only the original application setting: setup replaces it with a generated checkout name and generates a separate testing name. Keep real personal passwords out of a committed `.env.example`. If needed, use inherited personal overrides in the primary checkout's `.ai-harness.config.local`:

```ini
# Optional personal connection overrides:
local_env.DB_USERNAME=harness_app
local_env.DB_PASSWORD='your-personal-application-password'

# Optional separate existing administrative account:
database_provision_username=local_database_admin
database_provision_password='your-admin-password'
```

Omit the administrative settings when the application account already has the required provisioning privileges. Both accounts must already exist. Install PHP 8.5 in Yerd and ensure the PHP launching the harness has `pdo_mysql`. Then run:

```bash
./.ai-harness doctor
./.ai-harness setup
./.ai-harness artisan test --parallel --processes=4
./.ai-harness cleanup
```

Doctor may report missing setup or runtime resources before the first setup. Hostname, HTTPS and PHP selection follow the existing runtime behavior. Linked worktrees inherit personal `local_env.*` and administrative credentials, read their own `.env` or `.env.example`, and automatically receive distinct database names. The harness does not copy the primary checkout's entire `.env`. `up` and `down` leave MariaDB running.

Parameterized agent generation also retains exported `AI_HARNESS_DB_HOST`, `AI_HARNESS_DB_PORT`, `AI_HARNESS_DB_SOCKET`, `AI_HARNESS_DB_USERNAME`, `AI_HARNESS_DB_PASSWORD`, and `AI_HARNESS_DB_DATABASE` values. Prefer `--local-env=DB_HOST=...` and other endpoint overrides when configuring from parameters. Provisioning credentials and parallel workers have dedicated parameters; generated agent files containing credentials need the same protection as `.env`.
