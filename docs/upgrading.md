# Upgrading

## Normal upgrades

For the shared runtime configuration change, follow [Shared runtime configuration (beta)](#shared-runtime-configuration-beta) before updating: the automatic refresh hook rejects removed keys.

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

## Shared runtime configuration (beta)

Before running `composer update mrkoopie/laravel-ai-harness`, update every configuration layer, including `.ai-harness.config.dist`, `.ai-harness.config`, and personal `.ai-harness.config.local` files inherited by worktrees:

- Replace `herd_php`, `valet_php`, or `yerd_php` with `php_version`.
- Replace `herd_secure`, `valet_secure`, or `yerd_secure` with `site_secure`.
- Replace `services=none|sail` with `service_manager=none|sail`.

The old keys are rejected; no compatibility aliases are provided. If several runtime-specific keys exist in one file, keep one shared setting for the selected runtime and remove the others.

Use host Composer for this transition, rather than `./.ai-harness composer`: the currently installed harness may still require the old schema. After changing the keys, run:

```bash
composer update mrkoopie/laravel-ai-harness
./.ai-harness setup
```

The Composer hook refreshes managed project files using the new configuration. If old keys remain during the package update, that hook fails. To recover, first install the package with scripts disabled:

```bash
composer update mrkoopie/laravel-ai-harness --no-scripts
```

Then replace the keys in every configuration layer as described above, and refresh and prepare the project:

```bash
./.ai-harness update
./.ai-harness setup
```

`--no-scripts` also skips unrelated Composer scripts; run any other project-required scripts separately after reviewing them. Sail service names, cloud settings, and ownership state keep their existing meaning.
