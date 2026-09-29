# Commands

Run all commands from the project root through the `./.ai-harness` bootstrap script:

```bash
./.ai-harness <command> [arguments]
```

`./vendor/bin/ai-harness` accepts the same commands. Use it only when `.ai-harness` does not exist yet, for example before `init`.

## Runtime commands

These commands run a tool in the configured runtime. Refer to [Runtimes](configuration.md#runtimes) for the exact command that each runtime uses.

| Command | Runs |
| --- | --- |
| `./.ai-harness artisan <args>` | Artisan |
| `./.ai-harness test <args>` | `artisan test` |
| `./.ai-harness composer <args>` | Composer |
| `./.ai-harness php <args>` | PHP |
| `./.ai-harness npm <args>` | npm |

Examples:

```bash
./.ai-harness artisan migrate
./.ai-harness artisan make:model Post --migration
./.ai-harness test --filter=ExampleTest
./.ai-harness test --parallel
./.ai-harness composer require laravel/sanctum
./.ai-harness php -v
./.ai-harness npm run build
```

The harness sends the arguments as an argument array. It does not use a shell command string. Options, spaces, and shell metacharacters go to the tool without change.

Runtime commands always operate in the current directory.

## Project file commands

| Command | Description |
| --- | --- |
| `init` | Create `.ai-harness.config` when no configuration file exists. Then write all managed project files. |
| `update` | Write all managed project files again from the installed package release. Like `init`, it creates `.ai-harness.config` only when no configuration file exists. |
| `doctor` | Check the configuration, the runtime tools, and the managed files. Returns a non-zero exit code when a check fails. |

`init` and `update` do not install dependencies, start containers, create databases, or change Git. Refer to [Installation](installation.md#init-and-update-scope).

### `doctor` output

`doctor` shows the detected environment, runtime, services, and agents. Then it shows one line for each check:

```text
Environment: local
Runtime: herd; services: sail; agents: codex, claude
OK .ai-harness bootstrap is executable
OK Laravel artisan entrypoint exists
OK Configuration loaded from .ai-harness.config
OK Automatic Composer refresh hooks are installed
OK Laravel Herd is available
OK Sail service manager is available
OK Agent instructions are installed
OK Codex local environment is installed
OK Claude hooks are installed
```

For each `FAIL` line, refer to [Troubleshooting](troubleshooting.md).

## Environment commands

| Command | Local behavior | Cloud behavior |
| --- | --- | --- |
| `setup` | Prepare the current checkout. | Same as `cloud setup`. |
| `cleanup` | Remove only resources that the harness owns. | Same as `cloud cleanup`. |
| `up` | Start the configured Sail services. | Same as `cloud setup`. |
| `down` | Stop the configured Sail services. Volumes stay. | Do nothing. Services stay available for resumed sessions. |

Refer to [Local environments](local-environments.md) for the details of each step.

## Herd and Valet maintenance

| Command | Description |
| --- | --- |
| `./.ai-harness prune-herd` | Find Herd sites whose checkout no longer exists, and remove them after you confirm. |
| `./.ai-harness prune-valet` | Find Valet sites whose checkout no longer exists, and remove them after you confirm. |

Refer to [Remove orphaned Herd sites](local-environments.md#remove-orphaned-herd-sites) and [Remove orphaned Valet sites](local-environments.md#remove-orphaned-valet-sites).

## Cloud commands

| Command | Description |
| --- | --- |
| `./.ai-harness cloud setup` | Prepare a cloud checkout. |
| `./.ai-harness cloud maintain` | Prepare a checkout again after the provider restores a cache. |
| `./.ai-harness cloud cleanup` | Remove only the owned testing databases. Development data stays. |

The `.ai-harness-cloud` script accepts `provision`, `setup`, `maintain`, and `cleanup`. `provision` installs system packages. The other actions call the cloud commands above. Refer to [Cloud environments](cloud.md).

## Hook command

```bash
./.ai-harness hook claude <event>
```

Claude Code calls this command from the hooks in `.claude/settings.json`. You do not run it yourself. Refer to [Coding agents](agents.md#claude-code-hooks).

## The `--path` option

`init`, `update`, `doctor`, `setup`, `cleanup`, `up`, `down`, `prune-herd`, `prune-valet`, and `cloud` accept `--path=/path/to/project`. Use it to operate on a different project directory:

```bash
./vendor/bin/ai-harness doctor --path=/path/to/project
```

The default is the current directory.
