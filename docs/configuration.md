# Configuration

The harness reads its settings from simple `key=value` files in the project root.

## Configuration files

The harness loads these files in sequence. A later file overrides an earlier file.

| Order | File | Purpose | Commit it? |
| --- | --- | --- | --- |
| 1 | `.ai-harness.config.dist` | Shared defaults, for example in a template repository. | Yes |
| 2 | `.ai-harness.config` | The project configuration. `init` creates it. | Yes |
| 3 | `.ai-harness.config.local` | Personal overrides for one developer. | No, Git ignores it |

Each file can contain some or all keys. A key that is not in a file keeps its value from the earlier file or the default.

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

When another MySQL server already uses port 3306, set `FORWARD_DB_PORT=3307` in `.env`. Refer to [Local environments](local-environments.md#mysql-with-sail).

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
