<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Environment;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/** Yerd 2.x JSON discovery and local versioned shims. */
final class Yerd
{
    /** @return array<string, mixed> */
    public function query(string $binary, string $root, string $command): array
    {
        $process = new Process([$binary, '--json', $command], $root);
        $process->setTimeout(10);
        try {
            $process->run();
        } catch (ExceptionInterface $exception) {
            throw new EnvironmentException('Cannot query Yerd: '.$exception->getMessage());
        }
        $data = json_decode($process->getOutput(), true);
        if (! $process->isSuccessful() || ! is_array($data) || ($data['type'] ?? null) !== $command) {
            throw new EnvironmentException("Cannot read Yerd {$command} JSON; check that Yerd 2.x and its daemon are running.");
        }

        return $data;
    }

    /** @return list<array<string, mixed>> */
    public function sites(string $binary, string $root): array
    {
        $sites = $this->query($binary, $root, 'sites')['sites'] ?? null;
        if (! is_array($sites) || ! array_is_list($sites)) {
            throw new EnvironmentException('Invalid Yerd sites response.');
        }
        foreach ($sites as $site) {
            if (! is_array($site) || ! is_string($site['name'] ?? null)
                || ! is_string($site['document_root'] ?? null) || ! is_string($site['kind'] ?? null)) {
                throw new EnvironmentException('Invalid Yerd site record.');
            }
        }

        return $sites;
    }

    /** @return array<string, mixed>|null */
    public function site(string $binary, string $root, string $name): ?array
    {
        foreach ($this->sites($binary, $root) as $site) {
            if ($site['name'] === $name) {
                return $site;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $site */
    public static function belongsTo(array $site, string $root): bool
    {
        $target = $site['document_root'] ?? null;

        return ($site['kind'] ?? null) === 'linked' && is_string($target)
            && realpath($target) !== false && realpath($target) === realpath($root);
    }

    public function tld(string $binary, string $root): string
    {
        $report = $this->query($binary, $root, 'status')['report'] ?? null;
        $tld = is_array($report) ? ($report['tld'] ?? null) : null;
        if (! is_string($tld) || preg_match('/^[a-z0-9]+(?:[.-][a-z0-9]+)*$/D', $tld) !== 1) {
            throw new EnvironmentException('Invalid Yerd TLD in status response.');
        }

        return $tld;
    }

    public function php(string $binary, string $root, ?string $configured = null): string
    {
        $site = $this->site($binary, $root, SiteName::forPath($root));
        if ($site !== null && ! self::belongsTo($site, $root)) {
            throw new EnvironmentException('The Yerd checkout site belongs to another directory.');
        }
        $version = $configured ?? ($site['php'] ?? null);
        if ($version === null) {
            $report = $this->query($binary, $root, 'status')['report'] ?? null;
            $version = is_array($report) ? ($report['default_php'] ?? null) : null;
        }
        if (! is_string($version) || preg_match('/^[5-9]\.\d{1,2}$/D', $version) !== 1) {
            throw new EnvironmentException('Cannot resolve the Yerd PHP version.');
        }
        $php = $this->shimDirectory($binary).'/php'.$version;
        if (! is_executable($php)) {
            throw new EnvironmentException("Yerd PHP {$version} shim is missing; install it with yerd install php {$version}.");
        }

        return $php;
    }

    /** Locate a PHP Composer script, avoiding Yerd's binary Composer shim. */
    public function composer(string $binary, ?string $composer): string
    {
        $phar = dirname($this->shimDirectory($binary)).'/tools/composer/composer.phar';
        foreach ([$phar, $composer] as $candidate) {
            if (! is_string($candidate) || ! is_file($candidate) || ! is_readable($candidate)) {
                continue;
            }
            $handle = fopen($candidate, 'rb');
            $header = $handle === false ? false : fread($handle, 512);
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_string($header) && str_contains($header, '<?php')) {
                return $candidate;
            }
        }

        throw new EnvironmentException('Yerd requires a readable Composer PHP script on PATH or its managed composer.phar; install Composer first.');
    }

    private function shimDirectory(string $binary): string
    {
        // Do not realpath the executable: Yerd dispatches shims using argv[0].
        $directory = dirname($binary);
        if (is_executable($directory.'/php') && realpath($directory.'/php') === realpath($binary)) {
            return $directory;
        }
        $home = getenv('HOME');
        if (! is_string($home) || $home === '') {
            throw new EnvironmentException('Cannot locate Yerd shims without HOME.');
        }
        $data = getenv('XDG_DATA_HOME');

        return PHP_OS_FAMILY === 'Darwin'
            ? $home.'/Library/Application Support/io.yerd.Yerd/bin'
            : (is_string($data) && str_starts_with($data, '/') ? $data : $home.'/.local/share').'/yerd/bin';
    }
}
