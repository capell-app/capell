<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Facades\CapellCore;
use Illuminate\Support\Facades\Artisan;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
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
    private const CACHE_COMMANDS = [
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
        'packages' => [
            'command' => 'capell:package-cache:clear',
            'message' => '✓ Capell package cache cleared',
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

        if (in_array('all', $cachesToClear, true)) {
            if ($this->shouldSkipOptimizeClearForTestbench()) {
                $reporter->report('Skipped optimize:clear; Testbench package manifests are shared across parallel tests');
            } else {
                try {
                    Artisan::call('optimize:clear');
                    $reporter->report('✓ All caches cleared');
                } catch (Throwable $exception) {
                    $reporter->report(sprintf('Skipped optimize:clear; %s', $exception->getMessage()));
                }
            }

            $this->clearOptionalCache('page', $reporter);
            $this->clearOptionalCache('packages', $reporter);
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

        CapellCore::clearExtensionCache();
    }

    /**
     * Under Testbench the application boots from a skeleton whose cached package manifests the
     * test harness relies on. Match the skeleton by name rather than by its vendor path, because
     * parallel test processes boot from per-process copies of it.
     */
    private function shouldSkipOptimizeClearForTestbench(): bool
    {
        return app()->runningUnitTests()
            && str_contains(app()->bootstrapPath(), 'testbench');
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
            $reporter->report(sprintf('Unable to clear %s; %s', $cacheCommand['command'], $throwable->getMessage()));

            return;
        }

        if ($exitCode !== 0) {
            $output = trim(Artisan::output());
            $reporter->report($output === ''
                ? sprintf('Unable to clear %s; command exited with status %d', $cacheCommand['command'], $exitCode)
                : sprintf('Unable to clear %s; %s', $cacheCommand['command'], $output));

            return;
        }

        $reporter->report($cacheCommand['message']);
    }

    private function commandExists(string $command): bool
    {
        return array_key_exists($command, Artisan::all());
    }
}
