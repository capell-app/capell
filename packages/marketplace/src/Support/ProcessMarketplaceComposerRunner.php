<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use Capell\Core\Support\Json\JsonCodec;
use Capell\Marketplace\Actions\RedactMarketplaceDiagnosticContextAction;
use Capell\Marketplace\Contracts\MarketplaceAuthenticatedComposerRunner;
use Capell\Marketplace\Data\MarketplaceComposerResultData;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class ProcessMarketplaceComposerRunner implements MarketplaceAuthenticatedComposerRunner
{
    public function require(string $composerName, string $versionConstraint, int $timeoutSeconds): MarketplaceComposerResultData
    {
        $composerHome = storage_path('framework/composer');
        $this->ensureDirectory($composerHome);

        return $this->runComposer($composerName, $versionConstraint, $timeoutSeconds, $composerHome);
    }

    /**
     * @param  array<string, mixed>  $composerAuth
     */
    public function requireWithComposerAuth(
        string $composerName,
        string $versionConstraint,
        int $timeoutSeconds,
        array $composerAuth,
    ): MarketplaceComposerResultData {
        $composerHome = storage_path('framework/composer/marketplace-auth-' . bin2hex(random_bytes(8)));
        $this->ensureDirectory($composerHome);
        $this->writeComposerAuth($composerHome, $composerAuth);

        try {
            return $this->redactComposerAuth(
                $this->runComposer($composerName, $versionConstraint, $timeoutSeconds, $composerHome),
            );
        } finally {
            $this->removeDirectory($composerHome);
        }
    }

    private function runComposer(
        string $composerName,
        string $versionConstraint,
        int $timeoutSeconds,
        string $composerHome,
    ): MarketplaceComposerResultData {
        $process = new Process([
            $this->phpCliBinary(),
            $this->composerBinary(),
            ...$this->composerRequireArguments($composerName, $versionConstraint),
        ], base_path(), $this->processEnvironment($composerHome));

        $process->setTimeout($timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new MarketplaceComposerResultData(
                exitCode: 124,
                output: $process->getOutput(),
                errorOutput: $process->getErrorOutput(),
                timedOut: true,
            );
        }

        return new MarketplaceComposerResultData(
            exitCode: $process->getExitCode() ?? 1,
            output: $process->getOutput(),
            errorOutput: $process->getErrorOutput(),
        );
    }

    /**
     * @param  array<string, mixed>  $composerAuth
     *
     * @throws JsonException
     */
    private function writeComposerAuth(string $composerHome, array $composerAuth): void
    {
        $path = $composerHome . '/auth.json';
        $written = @file_put_contents(
            $path,
            JsonCodec::encode($composerAuth, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        throw_if($written === false, RuntimeException::class, 'Unable to write Composer authentication file.');

        @chmod($path, 0600);
    }

    private function phpCliBinary(): string
    {
        $php = (new ExecutableFinder)->find('php');

        throw_if(! is_string($php) || $php === '', RuntimeException::class, 'PHP CLI binary could not be found.');

        return $php;
    }

    private function composerBinary(): string
    {
        $composer = (new ExecutableFinder)->find('composer');

        throw_if(! is_string($composer) || $composer === '', RuntimeException::class, 'Composer binary could not be found.');

        return $composer;
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        $created = @mkdir($path, 0755, true);

        throw_unless(
            $created || is_dir($path),
            RuntimeException::class,
            'Unable to create Composer home directory: ' . $path,
        );
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.') {
                continue;
            }

            if ($item === '..') {
                continue;
            }

            $itemPath = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);

                continue;
            }

            @unlink($itemPath);
        }

        @rmdir($path);
    }

    private function redactComposerAuth(MarketplaceComposerResultData $result): MarketplaceComposerResultData
    {
        $redacted = RedactMarketplaceDiagnosticContextAction::run([
            'output' => $result->output,
            'error_output' => $result->errorOutput,
        ]);

        return new MarketplaceComposerResultData(
            exitCode: $result->exitCode,
            output: is_string($redacted['output'] ?? null) ? $redacted['output'] : '[redacted]',
            errorOutput: is_string($redacted['error_output'] ?? null) ? $redacted['error_output'] : '[redacted]',
            timedOut: $result->timedOut,
        );
    }

    /**
     * @return array<int, string>
     */
    private function composerRequireArguments(string $composerName, string $versionConstraint): array
    {
        return [
            '--no-cache',
            'require',
            '--no-interaction',
            '--prefer-dist',
            '--with-all-dependencies',
            sprintf('%s:%s', $composerName, $versionConstraint),
        ];
    }

    /**
     * @return array<string, string|false>
     */
    private function processEnvironment(string $composerHome): array
    {
        $home = getenv('HOME');

        return [
            'COMPOSER_HOME' => $composerHome,
            'COMPOSER_AUTH' => false,
            'COMPOSER_TOKEN' => false,
            'GIT_ASKPASS' => false,
            'GIT_TERMINAL_PROMPT' => '0',
            'GITHUB_TOKEN' => false,
            'GITHUB_AUTH_TOKEN' => false,
            'GITLAB_TOKEN' => false,
            'HOME' => is_string($home) && $home !== '' ? $home : $composerHome,
            'PACKAGIST_TOKEN' => false,
            'SSH_AUTH_SOCK' => false,
        ];
    }
}
