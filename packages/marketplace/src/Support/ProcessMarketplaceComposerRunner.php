<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use Capell\Core\Support\Deployment\ReleaseRootWriteGuard;
use Capell\Core\Support\Process\RuntimeBinaryResolver;
use Capell\Marketplace\Actions\RedactMarketplaceDiagnosticContextAction;
use Capell\Marketplace\Contracts\MarketplaceAuthenticatedComposerRunner;
use Capell\Marketplace\Data\MarketplaceComposerResultData;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class ProcessMarketplaceComposerRunner implements MarketplaceAuthenticatedComposerRunner
{
    public function __construct(
        private readonly ReleaseRootWriteGuard $releaseRootWriteGuard = new ReleaseRootWriteGuard,
        private readonly RuntimeBinaryResolver $binaryResolver = new RuntimeBinaryResolver,
        private readonly MarketplaceComposerAuthWorkspace $authWorkspace = new MarketplaceComposerAuthWorkspace,
    ) {}

    public function require(string $composerName, string $versionConstraint, int $timeoutSeconds): MarketplaceComposerResultData
    {
        $this->assertReleaseRootWritable();
        $this->authWorkspace->sweep();

        $composerHome = storage_path('framework/composer');
        $this->authWorkspace->ensureDirectory($composerHome);

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
        $this->assertReleaseRootWritable();
        $this->authWorkspace->sweep();

        $composerHome = $this->authWorkspace->create();
        $this->authWorkspace->writeAuthFile($composerHome, $composerAuth);

        try {
            return $this->redactComposerAuth(
                $this->runComposer($composerName, $versionConstraint, $timeoutSeconds, $composerHome),
            );
        } finally {
            $this->authWorkspace->removeDirectory($composerHome);
        }
    }

    private function assertReleaseRootWritable(): void
    {
        $this->releaseRootWriteGuard->assertWritable(
            operation: 'Installing a Marketplace extension with Composer',
            relativePaths: ['composer.json', 'composer.lock', 'vendor'],
            requiresServerSideTooling: true,
        );
    }

    private function runComposer(
        string $composerName,
        string $versionConstraint,
        int $timeoutSeconds,
        string $composerHome,
    ): MarketplaceComposerResultData {
        $this->authWorkspace->ensureDirectory($this->composerCacheDirectory());

        $process = new Process([
            ...$this->binaryResolver->composer(),
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
     * --no-scripts keeps a package's own Composer scripts from running as the web
     * user, which also suppresses Laravel's package:discover; the install job
     * rebuilds the manifests itself afterwards. --no-audit and --no-progress keep
     * a non-interactive run from spending its timeout on output nobody reads.
     *
     * @return array<int, string>
     */
    private function composerRequireArguments(string $composerName, string $versionConstraint): array
    {
        return [
            // A global option, so it has to precede the command.
            ...($this->cacheDisabled() ? ['--no-cache'] : []),
            'require',
            '--no-interaction',
            '--no-scripts',
            '--no-audit',
            '--no-progress',
            '--prefer-dist',
            '--with-all-dependencies',
            sprintf('%s:%s', $composerName, $versionConstraint),
        ];
    }

    /**
     * Off by default. Forcing --no-cache re-downloads every dependency on every
     * install, which is slow everywhere and actively hostile on a metered or
     * rate-limited host.
     */
    private function cacheDisabled(): bool
    {
        return (bool) config('capell.process.composer.no_cache', false);
    }

    private function composerCacheDirectory(): string
    {
        $configured = config('capell.process.composer.cache_dir');

        return is_string($configured) && $configured !== ''
            ? $configured
            : storage_path('framework/composer/cache');
    }

    private function composerMemoryLimit(): string
    {
        $configured = config('capell.process.composer.memory_limit', '-1');

        return is_scalar($configured) && (string) $configured !== '' ? (string) $configured : '-1';
    }

    /**
     * Corporate networks and some shared hosts have no outbound access at all
     * without these, so Composer has to be told about them explicitly rather
     * than left to whatever the queue worker happened to inherit.
     *
     * @return array<string, string>
     */
    private function proxyEnvironment(): array
    {
        $proxyEnvironment = [];

        foreach (['HTTP_PROXY', 'HTTPS_PROXY', 'NO_PROXY'] as $key) {
            // curl reads the lower-case spelling, Composer the upper-case one,
            // and hosts set either.
            foreach ([$key, strtolower($key)] as $variant) {
                $value = getenv($variant);

                if (is_string($value) && $value !== '') {
                    $proxyEnvironment[$variant] = $value;
                }
            }
        }

        return $proxyEnvironment;
    }

    /**
     * @return array<string, string|false>
     */
    private function processEnvironment(string $composerHome): array
    {
        $home = getenv('HOME');

        return [
            ...$this->proxyEnvironment(),
            'COMPOSER_CACHE_DIR' => $this->composerCacheDirectory(),
            'COMPOSER_HOME' => $composerHome,
            'COMPOSER_MEMORY_LIMIT' => $this->composerMemoryLimit(),
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
