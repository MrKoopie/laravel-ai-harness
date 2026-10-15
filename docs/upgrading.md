# Upgrading

> [!WARNING]
> **Upgrade to 0.6.0: configuration keys were removed without compatibility aliases.**
> Rename them in every configuration layer **before running Composer**. Otherwise, the automatic refresh hook fails and harness commands reject the configuration. A `^0.5` constraint does not allow `0.6.0`; the commands below also update that constraint.

## Upgrade to 0.6.0

### 1. Rename the removed keys

| Removed key | Replacement | Value |
| --- | --- | --- |
| `herd_php` | `php_version` | Keep the selected PHP version, for example `8.4`. |
| `valet_php` | `php_version` | Keep the selected PHP version. |
| `yerd_php` | `php_version` | Keep the selected PHP version. |
| `herd_secure` | `site_secure` | Keep the boolean value. |
| `valet_secure` | `site_secure` | Keep the boolean value. |
| `yerd_secure` | `site_secure` | Keep the boolean value. |
| `services` | `service_manager` | Keep `none` or `sail`. |

Check every existing `.ai-harness.config.dist`, `.ai-harness.config`, `.ai-harness.config.global`, and `.ai-harness.config.local`. Include personal files in the primary checkout that linked worktrees inherit, and overrides in each worktree. For nested applications, check the corresponding application directory in each checkout. Updating a shared file does not migrate personal files.

If several runtime-specific keys exist in one file, keep a single `php_version` and a single `site_secure` for the selected runtime, and remove the old keys for every runtime. Duplicate replacement keys are also rejected. Keep per-file overrides in their existing layers so configuration precedence stays the same.

For example, change:

```ini
runtime=herd
herd_php=8.4
herd_secure=false
services=sail
sail_services=mysql,redis
```

to:

```ini
runtime=herd
php_version=8.4
site_secure=false
service_manager=sail
sail_services=mysql,redis
```

`sail_services` still lists Sail service names; do not rename it. `runtime`, `agents`, `worktrees`, `local_env.*`, and cloud settings keep their meaning. Do not rename keys in `.ai-harness.state.json`; resource ownership state is unchanged.

The shared site settings follow the selected Herd, Valet, or Yerd runtime. An empty `php_version` preserves an existing PHP selection; it does not remove a site pin. `site_secure` defaults to `true`. Native PHP, Sail, and cloud execution ignore these two site settings. See [all configuration keys](configuration.md#all-keys).

### 2. Install 0.6 and refresh the project

Use host Composer for this transition: the old `./.ai-harness composer` command may still require the old configuration schema.

For a project dependency, run from the Laravel project:

```bash
composer require --dev 'mrkoopie/laravel-ai-harness:^0.6'
./.ai-harness update
./.ai-harness doctor
./.ai-harness setup
```

The Composer hook normally refreshes the managed files automatically; the explicit `update` also covers projects without that hook. Commit the renamed shared configuration, `composer.json`, `composer.lock`, and refreshed managed files, including `.ai-harness-cloud` when cloud support is enabled. Each collaborator must rename their personal keys before updating or using the new version.

For an existing global installation, update the global package once, then refresh each configured project:

```bash
composer global require 'mrkoopie/laravel-ai-harness:^0.6'
cd /path/to/your/laravel-project
ai-harness update
ai-harness doctor
ai-harness setup
```

Repeat the refresh in existing linked worktrees that you use. New global installations start with the current keys; follow [Global installation](installation.md#global-installation) to initialize them. Global mode keeps its generated configuration and integrations out of Git through `.git/info/exclude`.

### Recovery after a failed Composer hook

If an update has already failed because removed keys remain, install the new package with scripts disabled using host Composer:

```bash
composer require --dev 'mrkoopie/laravel-ai-harness:^0.6' --no-scripts
```

For a global installation, use `composer global require 'mrkoopie/laravel-ai-harness:^0.6' --no-scripts` instead. Then rename the keys in every configuration layer as described above and run `update`, `doctor`, and `setup` using the commands for your installation mode.

`--no-scripts` also skips unrelated Composer scripts. Review and run any other project-required scripts separately after the configuration is valid. Renaming keys is manual; rerunning Composer alone does not migrate them.

<a id="shared-runtime-configuration-beta"></a>

The former “Shared runtime configuration (beta)” instructions are now covered by [Upgrade to 0.6.0](#upgrade-to-060).

## Normal upgrades

When crossing from an earlier release to 0.6, follow [Upgrade to 0.6.0](#upgrade-to-060) first.

For other upgrades, update the package with Composer:

```bash
composer update mrkoopie/laravel-ai-harness
```

The Composer hook refreshes the managed project files automatically. Commit the changed files. Refer to [Installation](installation.md#update-the-package).

When you use cloud environments, make sure that the refreshed `.ai-harness-cloud` is committed.

## Upgrade from version 0.1

Version 0.2 keeps the old `artisan ai-harness:update` command and the old service provider class for one release. Thus, an existing v0.1 Composer hook can complete the upgrade. The compatibility command replaces the old hook with the new guarded `./vendor/bin/ai-harness update` hook.

### Automatic upgrade

Run `composer update mrkoopie/laravel-ai-harness`. The old hook calls the compatibility command, which migrates the configuration and writes the new files.

### Manual upgrade

For recovery, disable the Composer scripts and run the compatibility command yourself:

```bash
composer update mrkoopie/laravel-ai-harness --no-scripts
php artisan ai-harness:update --ansi
```

Use the Artisan command, not `./vendor/bin/ai-harness update`. The Artisan command migrates the old configuration and environment choices before it creates the new project files.

### What the migration does

When no `.ai-harness.config`, `.ai-harness.config.dist`, or `.ai-harness.config.local` exists, the bridge translates the old choices:

- Codex, Claude, Herd, Docker, and PHP version choices become the new keys.
- Old Docker support becomes `service_manager=sail` with `sail_services=mysql`.

An existing configuration in the new format always wins.

The bridge does not delete files silently. It shows a warning for:

- Unsupported skills generation.
- Unsupported Polyscope generation.
- Obsolete generated files.

Remove these files yourself after you read the warnings.

### After the first refresh

Use `./.ai-harness update` from then on. The Artisan compatibility command is temporary. A later release, possibly 0.3, can remove it.
