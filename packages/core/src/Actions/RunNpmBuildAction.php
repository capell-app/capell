<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

class RunNpmBuildAction
{
    use AsFake;
    use AsObject;

    private const int BUILD_TIMEOUT_SECONDS = 300;

    public function handle(bool $isDev = false): void
    {
        $this->ensureNpmHost();
        $installResult = $this->runCommand('npm install');
        if (! $installResult->successful()) {
            $this->throwBuildFailedException($installResult);
        }

        $command = $isDev ? 'npm run dev' : 'npm run build';

        $result = $this->runCommand($command);

        if ($result->successful()) {
            return;
        }

        if ($this->failedBecauseNativeBindingIsMissing($result->errorOutput(), $result->output())) {
            $installResult = $this->runCommand('npm install');

            if (! $installResult->successful()) {
                $this->throwBuildFailedException($installResult);
            }

            $result = $this->runCommand($command);

            if ($result->successful()) {
                return;
            }
        }

        $this->throwBuildFailedException($result);
    }

    private function ensureNpmHost(): void
    {
        $path = base_path('package.json');
        $package = is_file($path) ? json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
        $declared = is_array($package) && is_string($package['packageManager'] ?? null)
            ? explode('@', $package['packageManager'], 2)[0]
            : '';
        $otherManagers = [];
        if ($declared !== '' && $declared !== 'npm') {
            $otherManagers[] = $declared;
        }

        foreach (['pnpm-lock.yaml' => 'pnpm', 'yarn.lock' => 'yarn', 'bun.lock' => 'bun', 'bun.lockb' => 'bun'] as $lockfile => $manager) {
            if (is_file(base_path($lockfile))) {
                $otherManagers[] = $manager;
            }
        }

        if ($otherManagers !== []) {
            throw new RuntimeException(sprintf(
                'This npm-only builder cannot run for an application using %s. Run php artisan capell:frontend-after-install --apply --no-interaction with the detected package manager, or use the host package manager install and production build commands.',
                implode(', ', array_unique($otherManagers)),
            ));
        }
    }

    private function failedBecauseNativeBindingIsMissing(string $errorOutput, string $output): bool
    {
        $combinedOutput = $errorOutput . $output;

        return str_contains($combinedOutput, 'Cannot find native binding')
            || str_contains($combinedOutput, "Cannot find module '@rollup/rollup-")
            || str_contains($combinedOutput, 'npm has a bug related to optional dependencies');
    }

    private function runCommand(string $command): ProcessResult
    {
        return Process::timeout(self::BUILD_TIMEOUT_SECONDS)->run($command);
    }

    private function throwBuildFailedException(ProcessResult $result): never
    {
        $errorOutput = $result->errorOutput();

        throw new RuntimeException(
            sprintf('npm build failed: %s', $errorOutput !== '' ? $errorOutput : $result->output()),
        );
    }
}
