<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use Capell\Core\Support\Json\JsonCodec;
use Capell\Core\Support\Process\RuntimeBinaryResolver;
use Capell\Marketplace\Contracts\MarketplaceComposerScriptRunner;
use Capell\Marketplace\Data\MarketplaceComposerResultData;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs a root-package Composer script event in its own subprocess.
 *
 * This deliberately shells out to Composer instead of calling the individual
 * commands: Composer is the only thing that knows what the application declared,
 * and by the time this runs the new package's autoload map is already on disk,
 * so the artisan calls inside those hooks boot cleanly.
 */
final class ProcessMarketplaceComposerScriptRunner implements MarketplaceComposerScriptRunner
{
    public function __construct(
        private readonly RuntimeBinaryResolver $binaryResolver = new RuntimeBinaryResolver,
        private readonly MarketplaceComposerEnvironment $environment = new MarketplaceComposerEnvironment,
        private readonly MarketplaceComposerAuthWorkspace $authWorkspace = new MarketplaceComposerAuthWorkspace,
    ) {}

    public function replayRootScript(string $event, int $timeoutSeconds): ?MarketplaceComposerResultData
    {
        if (! $this->applicationDeclaresScript($event)) {
            return null;
        }

        $composerHome = storage_path('framework/composer');
        $this->authWorkspace->ensureDirectory($composerHome);
        $this->authWorkspace->ensureDirectory($this->environment->cacheDirectory());

        $process = new Process(
            [...$this->binaryResolver->composer(), 'run-script', '--no-interaction', $event],
            base_path(),
            $this->environment->variables($composerHome),
        );

        $process->setTimeout($timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new MarketplaceComposerResultData(
                exitCode: 124,
                output: $process->getOutput(),
                errorOutput: $process->getErrorOutput(),
                timedOut: true,
            )->redacted();
        }

        // The application owns these hooks and Capell cannot see what they
        // print. A hook that dumps its environment on failure would otherwise
        // reach the error reporter verbatim, so this path redacts exactly as
        // the authenticated require path does.
        return new MarketplaceComposerResultData(
            exitCode: $process->getExitCode() ?? 1,
            output: $process->getOutput(),
            errorOutput: $process->getErrorOutput(),
        )->redacted();
    }

    /**
     * Read from the application's own composer.json at runtime. Capell has no
     * build-time knowledge of it, and an application that declares nothing for
     * this event must not be handed a Composer error for a script that does not
     * exist.
     */
    private function applicationDeclaresScript(string $event): bool
    {
        $path = base_path('composer.json');

        if (! is_file($path)) {
            return false;
        }

        $contents = @file_get_contents($path);

        if (! is_string($contents) || $contents === '') {
            return false;
        }

        $manifest = JsonCodec::decodeArray($contents);
        $scripts = $manifest['scripts'] ?? null;

        if (! is_array($scripts)) {
            return false;
        }

        $declared = $scripts[$event] ?? null;

        return is_string($declared) ? $declared !== '' : is_array($declared) && $declared !== [];
    }
}
