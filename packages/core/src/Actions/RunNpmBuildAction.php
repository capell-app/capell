<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

class RunNpmBuildAction
{
    use AsObject;

    private const int BUILD_TIMEOUT_SECONDS = 300;

    public function handle(bool $isDev = false): void
    {
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
