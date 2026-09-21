<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Files;

use MrKoopie\LaravelAiHarness\Config\ConfigLoader;

final readonly class ProjectSynchronizer
{
    /** Create the file-only project synchronizer. */
    public function __construct(
        private ConfigLoader $configLoader,
        private ProjectInstaller $installer,
        private ComposerScripts $composerScripts,
    ) {}

    /** Refresh project integration without preparing runtime resources. */
    public function sync(string $root): SyncResult
    {
        $createdConfig = $this->installer->ensureConfig($root);
        $config = $this->configLoader->load($root);
        $written = $this->installer->install($root, $config);

        if ($this->composerScripts->sync($root)) {
            $written[] = 'composer.json';
        }

        return new SyncResult($createdConfig, array_values(array_unique($written)));
    }
}
