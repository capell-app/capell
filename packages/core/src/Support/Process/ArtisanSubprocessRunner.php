<?php

declare(strict_types=1);

namespace Capell\Core\Support\Process;

use Capell\Core\Support\Composer\ComposerProcessEnvironment;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

final readonly class ArtisanSubprocessRunner
{
    public function __construct(private ProcessFactoryInterface $processFactory) {}

    /**
     * @param  list<string>  $arguments
     * @param  callable(string): void  $onLine
     */
    public function run(array $arguments, callable $onLine, ?float $timeout = 120): int
    {
        $process = $this->processFactory->make(
            [$this->resolvePhpCliBinary(), 'artisan', ...$arguments],
            base_path(),
            ArtisanProcessEnvironment::prepare(ComposerProcessEnvironment::forInstall($_SERVER)),
        );
        $process->setTimeout($timeout);
        $process->run(function (string $type, string $buffer) use ($onLine): void {
            foreach (explode("\n", trim($buffer)) as $line) {
                if ($line !== '') {
                    $onLine($line);
                }
            }
        });

        return $process->getExitCode() ?? 1;
    }

    private function resolvePhpCliBinary(): string
    {
        $finder = new ExecutableFinder;
        $configuredBinary = config('capell-installer.php_binary');
        $candidates = array_values(array_unique(array_filter([
            is_string($configuredBinary) ? $configuredBinary : null,
            'php',
            PHP_BINARY,
        ])));

        foreach ($candidates as $candidate) {
            $resolvedBinary = str_contains($candidate, DIRECTORY_SEPARATOR)
                ? (is_file($candidate) && is_executable($candidate) ? $candidate : null)
                : $finder->find($candidate);

            if ($resolvedBinary !== null && ! str_contains(basename($resolvedBinary), 'php-fpm')) {
                return $resolvedBinary;
            }
        }

        throw new RuntimeException('Unable to locate a CLI PHP binary for the Artisan subprocess.');
    }
}
