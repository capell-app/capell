<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Support\Composer\ComposerProcessEnvironment;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class PreflightExtraPackagesAction
{
    use AsObject;

    public function __construct(
        private readonly ProcessFactoryInterface $processFactory,
    ) {}

    /**
     * @param  array<int, string>  $packages  Composer package names (e.g. "vendor/name").
     */
    public function handle(array $packages, ProgressReporter $reporter): void
    {
        if ($packages === []) {
            return;
        }

        $reporter->step('Checking selected packages can be installed via Composer…');

        $packageArgs = app()->isLocal()
            ? array_map(fn (string $name): string => str_contains($name, ':') ? $name : $name . ':*', $packages)
            : $packages;

        $command = array_merge([
            'composer',
            'require',
            '--dry-run',
            '--no-interaction',
            '--prefer-dist',
            '--with-all-dependencies',
        ], $packageArgs);

        $composerFiles = $this->snapshotComposerFiles();

        try {
            $process = $this->processFactory->make($command, base_path(), ComposerProcessEnvironment::forInstall($_SERVER));
            $process->setTimeout(600);
            $process->run(function (string $type, string $buffer) use ($reporter): void {
                foreach (explode("\n", trim($buffer)) as $line) {
                    if ($line !== '') {
                        $reporter->report($line);
                    }
                }
            });
        } finally {
            $this->restoreComposerFiles($composerFiles);
        }

        if ($process->isSuccessful()) {
            $reporter->report(sprintf('✓ Composer can install: %s', implode(', ', $packages)));

            return;
        }

        $errorOutput = trim($process->getErrorOutput());
        $output = trim($process->getOutput());
        $message = $errorOutput !== '' ? $errorOutput : ($output !== '' ? $output : 'Unknown error.');

        throw new RuntimeException(
            sprintf('Selected packages cannot be installed via Composer [%s]: %s', implode(', ', $packages), $message),
        );
    }

    /**
     * @return array<string, string|null>
     */
    private function snapshotComposerFiles(): array
    {
        return collect([
            base_path('composer.json'),
            base_path('composer.lock'),
        ])->mapWithKeys(function (string $path): array {
            if (! is_file($path)) {
                return [$path => null];
            }

            $contents = file_get_contents($path);

            return [$path => is_string($contents) ? $contents : null];
        })->all();
    }

    /**
     * @param  array<string, string|null>  $composerFiles
     */
    private function restoreComposerFiles(array $composerFiles): void
    {
        foreach ($composerFiles as $path => $contents) {
            if ($contents === null) {
                if (is_file($path)) {
                    unlink($path);
                }

                continue;
            }

            file_put_contents($path, $contents);
        }
    }
}
