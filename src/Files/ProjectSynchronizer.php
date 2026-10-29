<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Files;

use MrKoopie\LaravelAiHarness\Config\ConfigLoader;
use MrKoopie\LaravelAiHarness\Support\GlobalInstallation;

final readonly class ProjectSynchronizer
{
    /** Create the file-only project synchronizer. */
    public function __construct(
        private ConfigLoader $configLoader,
        private ProjectInstaller $installer,
        private ComposerScripts $composerScripts,
    ) {}

    /** Refresh project integration without preparing runtime resources.
     * @param  array<string, string>  $overrides
     */
    public function sync(string $root, bool $global = false, array $overrides = [], bool $persistAgentEnvironment = false): SyncResult
    {
        $global = $global || GlobalInstallation::active($root);
        $createdConfig = $this->installer->ensureConfig($root, $global);
        $config = $this->configLoader->load($root, $overrides);
        $written = $this->installer->install($root, $config, $global, $persistAgentEnvironment);

        if (! $global && $this->composerScripts->sync($root)) {
            $written[] = 'composer.json';
        }

        return new SyncResult($createdConfig, array_values(array_unique($written)), $global ? GlobalInstallation::CONFIG_FILE : '.ai-harness.config');
    }
}
