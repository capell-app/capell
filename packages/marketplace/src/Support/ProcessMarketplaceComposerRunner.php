<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use Capell\Core\Support\Deployment\ReleaseRootWriteGuard;
use Capell\Core\Support\Process\RuntimeBinaryResolver;
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
        private readonly MarketplaceComposerEnvironment $environment = new MarketplaceComposerEnvironment,
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
            return $this->runComposer($composerName, $versionConstraint, $timeoutSeconds, $composerHome)->redacted();
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
        $this->authWorkspace->ensureDirectory($this->environment->cacheDirectory());

        $process = new Process([
            ...$this->binaryResolver->composer(),
            ...$this->composerRequireArguments($composerName, $versionConstraint),
        ], base_path(), $this->environment->variables($composerHome));

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
     * --no-scripts keeps the application's Composer hooks from firing against a
     * half-written autoload map. Every one of them is suppressed, not only
     * Laravel's package:discover, so the install job replays the application's
     * whole post-autoload-dump chain once the require has finished — see
     * MarketplaceComposerScriptRunner. --no-audit and --no-progress keep
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
}
