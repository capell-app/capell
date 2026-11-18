<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Process\ArtisanSubprocessRunner;
use Illuminate\Support\Facades\Artisan;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Throwable;

class ClearCachesAction
{
    use AsFake;
    use AsObject;

    /**
     * @pest-mutate-ignore
     *
     * @var array<string, array{command: string, message: string, optional: bool}>
     */
    private const array CACHE_COMMANDS = [
        'page' => [
            'command' => 'capell:html-cache:clear',
            'message' => '✓ HTML cache cleared',
            'optional' => true,
        ],
        'config' => [
            'command' => 'config:clear',
            'message' => '✓ Config cache cleared',
            'optional' => false,
        ],
        'views' => [
            'command' => 'view:clear',
            'message' => '✓ Views cache cleared',
            'optional' => false,
        ],
        'admin' => [
            'command' => 'capell:admin-clear-cache',
            'message' => '✓ Capell admin cache cleared',
            'optional' => true,
        ],
        'components' => [
            'command' => 'capell:clear-components-cache',
            'message' => '✓ Capell components cache cleared',
            'optional' => true,
        ],
        'widgets' => [
            'command' => 'capell:admin-clear-widgets-cache',
            'message' => '✓ Capell widgets cache cleared',
            'optional' => true,
        ],
        'configurators' => [
            'command' => 'capell:admin-clear-configurators-cache',
            'message' => '✓ Capell configurators cache cleared',
            'optional' => true,
        ],
        'filament-components' => [
            'command' => 'filament:clear-cached-components',
            'message' => '✓ Filament components cache cleared',
            'optional' => true,
        ],
    ];

    /** @param array<string> $cachesToClear */
    public function handle(array $cachesToClear, ProgressReporter $reporter): void
    {
        if ($cachesToClear === []) {
            return;
        }

        $reporter->step('Clearing caches…');
        CapellCore::clearExtensionCache();

        $clearingAll = in_array('all', $cachesToClear, true);
        if ($clearingAll) {
            if ($this->shouldSkipOptimizeClear()) {
                $reporter->report('Skipped optimize:clear; capell.install.skip_optimize_clear is enabled');
            } else {
                $this->callCacheCommand([
                    'command' => 'optimize:clear',
                    'message' => '✓ All caches cleared',
                    'optional' => false,
                ], $reporter);
            }

            $this->clearOptionalCache('page', $reporter);
        }

        foreach (self::CACHE_COMMANDS as $key => $cacheCommand) {
            if (! in_array($key, $cachesToClear, true)) {
                continue;
            }

            if ($cacheCommand['optional'] && ! $this->commandExists($cacheCommand['command'])) {
                $reporter->report(sprintf('Skipped %s; command is not available', $cacheCommand['command']));

                continue;
            }

            $this->callCacheCommand($cacheCommand, $reporter);
        }

        if ($clearingAll || in_array('packages', $cachesToClear, true)) {
            $this->rebuildPackageCache($reporter);
        }

        CapellCore::clearExtensionCache();
    }

    private function rebuildPackageCache(ProgressReporter $reporter): void
    {
        $cacheCommand = [
            'command' => 'capell:package-cache',
            'message' => '✓ Capell package cache rebuilt',
            'optional' => false,
        ];

        if ($this->commandExists($cacheCommand['command'])) {
            $this->callCacheCommand($cacheCommand, $reporter);

            return;
        }

        // An HTTP installer request may not have the console command registered.
        $this->runCacheCommandInFreshProcess($cacheCommand['command'], $reporter);
        $reporter->report($cacheCommand['message']);
    }

    /**
     * Test harnesses opt out through configuration, because their cached package manifests are shared
     * across parallel tests; the decision never depends on where the application lives.
     */
    private function shouldSkipOptimizeClear(): bool
    {
        return (bool) config('capell.install.skip_optimize_clear', false);
    }

    private function clearOptionalCache(string $key, ProgressReporter $reporter): void
    {
        $cacheCommand = self::CACHE_COMMANDS[$key] ?? null;

        if ($cacheCommand === null) {
            return;
        }

        if ($cacheCommand['optional'] && ! $this->commandExists($cacheCommand['command'])) {
            $reporter->report(sprintf('Skipped %s; command is not available', $cacheCommand['command']));

            return;
        }

        $this->callCacheCommand($cacheCommand, $reporter);
    }

    /** @param array{command: string, message: string, optional: bool} $cacheCommand */
    private function callCacheCommand(array $cacheCommand, ProgressReporter $reporter): void
    {
        try {
            $exitCode = Artisan::call($cacheCommand['command']);
        } catch (Throwable $throwable) {
            if ($cacheCommand['command'] === 'optimize:clear' && $throwable instanceof CommandNotFoundException) {
                // A provider installed in this process can register an optimize hook
                // after the console application has collected its commands.
                $this->runCacheCommandInFreshProcess('optimize:clear', $reporter);
                $reporter->report('→ optimize:clear ran in a fresh process');
                $reporter->report($cacheCommand['message']);

                return;
            }

            $message = sprintf('Unable to clear %s; %s', $cacheCommand['command'], $throwable->getMessage());
            $reporter->report($message);

            throw new RuntimeException($message, $throwable->getCode(), previous: $throwable);
        }

        if ($exitCode !== 0) {
            $output = trim(Artisan::output());
            $message = $output === ''
                ? sprintf('Unable to clear %s; command exited with status %d', $cacheCommand['command'], $exitCode)
                : sprintf('Unable to clear %s; %s', $cacheCommand['command'], $output);
            $reporter->report($message);

            throw new RuntimeException($message);
        }

        $reporter->report($cacheCommand['message']);
    }

    private function runCacheCommandInFreshProcess(string $command, ProgressReporter $reporter): void
    {
        $lines = [];

        try {
            $exitCode = resolve(ArtisanSubprocessRunner::class)->run(
                [$command, '--no-interaction'],
                function (string $line) use (&$lines): void {
                    $lines[] = $line;
                },
                timeout: 120,
            );
        } catch (Throwable $throwable) {
            $output = trim(implode("\n", [...$lines, $throwable->getMessage()]));
            $message = sprintf('Unable to clear %s; %s', $command, $output);
            $reporter->report($message);

            throw new RuntimeException($message, $throwable->getCode(), previous: $throwable);
        }

        if ($exitCode !== 0) {
            $output = trim(implode("\n", $lines));
            $message = $output === ''
                ? sprintf('Unable to clear %s; command exited with status %d', $command, $exitCode)
                : sprintf('Unable to clear %s; %s', $command, $output);
            $reporter->report($message);

            throw new RuntimeException($message);
        }
    }

    private function commandExists(string $command): bool
    {
        return array_key_exists($command, Artisan::all());
    }
}
