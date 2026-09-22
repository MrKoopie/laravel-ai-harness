<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Console;

use MrKoopie\LaravelAiHarness\Environment\CommandFactory;
use MrKoopie\LaravelAiHarness\Environment\ExecutionEnvironment;
use MrKoopie\LaravelAiHarness\Environment\HerdSites;
use MrKoopie\LaravelAiHarness\Process\ProcessRunner;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

final class PruneHerdCommand extends ProjectCommand
{
    /** Create an explicitly confirmed orphan-site cleanup command. */
    public function __construct(private readonly CommandFactory $commands, private readonly ProcessRunner $processes)
    {
        parent::__construct('prune-herd');
    }

    /** Configure report-only and site discovery options. */
    protected function configure(): void
    {
        $this->setDescription('Inspect and optionally remove verified orphan Herd sites; never drops databases');
        $this->configureProjectPath();
        $this->addOption('sites-path', null, InputOption::VALUE_REQUIRED, 'Inspect an alternate sites directory (report only)');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report without removing sites');
    }

    /** Confirm each missing site and revalidate ownership before each Herd mutation. */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (ExecutionEnvironment::current()->isCloud()) {
            $output->writeln('Herd pruning is available only in local environments.');

            return self::FAILURE;
        }

        $root = $this->projectPath($input);
        $override = $input->getOption('sites-path');
        $directory = is_string($override) ? $override : HerdSites::directory();
        $directory = realpath($directory) ?: $directory;
        $sites = new HerdSites;
        $orphans = $sites->orphans($directory);
        $reportOnly = ! $input->isInteractive() || $input->getOption('dry-run')
            || $directory !== (realpath(HerdSites::directory()) ?: HerdSites::directory());
        $failed = false;

        if ($orphans === []) {
            $output->writeln('No orphaned AI Harness Herd sites were found.');
        }

        foreach ($orphans as $site => $target) {
            $output->writeln($site.' -> '.$target, OutputInterface::OUTPUT_RAW);

            if ($reportOnly) {
                continue;
            }

            $question = new ConfirmationQuestion('Remove this Herd site and its HTTPS configuration? [y/N] ', false);

            if (! (new QuestionHelper)->ask($input, $output, $question)) {
                continue;
            }

            foreach (['unsecure', 'unlink'] as $action) {
                if ($sites->orphanTarget($directory, $site) !== $target) {
                    $output->writeln('<error>Site changed since inspection; stopped cleanup.</error>');
                    $failed = true;

                    break;
                }

                if ($this->processes->run($this->commands->herd($action, $site), $root, $output) !== 0) {
                    $output->writeln('<error>Herd cleanup failed; keep the link for a retry.</error>');
                    $failed = true;

                    break;
                }
            }
        }

        if ($reportOnly) {
            $output->writeln('No changes were made (report only).');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
