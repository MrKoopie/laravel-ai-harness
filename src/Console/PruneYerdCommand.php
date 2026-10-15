<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Console;

use Closure;
use MrKoopie\LaravelAiHarness\Environment\CommandFactory;
use MrKoopie\LaravelAiHarness\Environment\ExecutionEnvironment;
use MrKoopie\LaravelAiHarness\Environment\SiteName;
use MrKoopie\LaravelAiHarness\Environment\Yerd;
use MrKoopie\LaravelAiHarness\Process\ProcessRunner;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

final class PruneYerdCommand extends ProjectCommand
{
    /** @var Closure(resource): bool */
    private readonly Closure $isTerminal;

    /** @param (Closure(resource): bool)|null $isTerminal */
    public function __construct(private readonly CommandFactory $commands, private readonly ProcessRunner $processes, ?Closure $isTerminal = null)
    {
        $this->isTerminal = $isTerminal ?? stream_isatty(...);
        parent::__construct('prune-yerd');
    }

    protected function configure(): void
    {
        $this->setDescription('Inspect and optionally remove verified orphan Yerd sites; never drops databases');
        $this->configureProjectPath();
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report without removing sites');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (ExecutionEnvironment::current()->isCloud()) {
            $output->writeln('Yerd pruning is available only in local environments.');

            return self::FAILURE;
        }
        $root = $this->projectPath($input);
        $binary = $this->commands->yerd('sites')[0];
        $yerd = new Yerd;
        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
        $reportOnly = ! $input->isInteractive() || $input->getOption('dry-run')
            || ! ($this->isTerminal)($stream ?? STDIN);
        $failed = false;
        $found = false;
        foreach ($yerd->sites($binary, $root) as $site) {
            if (! $this->orphan($site)) {
                continue;
            }
            $found = true;
            $name = (string) $site['name'];
            $target = (string) $site['document_root'];
            $output->writeln($name.' -> '.$target, OutputInterface::OUTPUT_RAW);
            if ($reportOnly || ! (new QuestionHelper)->ask($input, $output, new ConfirmationQuestion('Remove this Yerd site and its HTTPS configuration? [y/N] ', false))) {
                continue;
            }
            // Yerd unlink removes the site's complete configuration, including TLS and PHP pin.
            $current = $yerd->site($binary, $root, $name);
            if ($current === null || ! $this->orphan($current) || $current['document_root'] !== $target) {
                $output->writeln('<error>Site changed since inspection; stopped cleanup.</error>');
                $failed = true;

                continue;
            }
            if ($this->processes->run($this->commands->yerd('unlink', $name), $root, $output) !== 0) {
                $failed = true;
            }
        }
        if (! $found) {
            $output->writeln('No orphaned AI Harness Yerd sites were found.');
        }
        if ($reportOnly) {
            $output->writeln('No changes were made (report only).');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @param array<string, mixed> $site */
    private function orphan(array $site): bool
    {
        $target = $site['document_root'] ?? null;
        $name = $site['name'] ?? null;
        if (($site['kind'] ?? null) !== 'linked' || ! is_string($target) || ! str_starts_with($target, '/') || ! is_string($name)) {
            return false;
        }
        clearstatcache();

        return ! file_exists($target) && ! is_link($target) && $name === SiteName::forPath($target);
    }
}
