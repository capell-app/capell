<?php

declare(strict_types=1);

namespace Capell\Core\Jobs;

use Capell\Core\Actions\Install\RunInstallAction;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Install\CacheProgressReporter;
use Capell\Core\Support\Install\InstallReportRedactor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use ReflectionProperty;
use Throwable;

final class RunCapellInstallJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const string LOCK_KEY = 'capell.install.lock';

    private const int CACHE_TTL = 7200;

    public int $timeout = 600;

    public int $tries = 3;

    public int $uniqueFor = self::CACHE_TTL;

    /** @param  array<string, string>  $tableChanges  Config keys to apply for the duration of the install. */
    public function __construct(
        private readonly InstallInputData $inputData,
        private readonly string $installId,
        private readonly array $tableChanges = [],
    ) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 60];
    }

    public function uniqueId(): string
    {
        return $this->installId;
    }

    public function handle(): void
    {
        $reporter = new CacheProgressReporter($this->installId, redactionSecrets: InstallReportRedactor::secrets($this->inputData->toArray()));

        $admitted = $this->duringUpdate(function () use ($reporter): bool {
            if ($this->hasBeenSuperseded()) {
                Cache::put($this->statusKey(), 'cancelled', self::CACHE_TTL);
                $reporter->error('Install cancelled because another install was started.');

                return false;
            }

            $reporter->markRunning();

            return true;
        });
        if (! $admitted) {
            return;
        }

        $previousConfig = [];
        foreach ($this->queuedTableChanges() as $key => $value) {
            $previousConfig[$key] = [config()->has($key), config($key)];
            config()->set($key, $value);
        }

        try {
            RunInstallAction::run($this->inputData, $reporter);
            $this->duringUpdate(function () use ($reporter): void {
                if ($this->hasBeenSuperseded()) {
                    Cache::put($this->statusKey(), 'cancelled', self::CACHE_TTL);

                    return;
                }

                $reporter->markComplete();
                $this->clearActiveLock();
            });
        } catch (Throwable $throwable) {
            $this->duringUpdate(function () use ($reporter, $throwable): void {
                $reporter->error('✗ ' . $throwable->getMessage());
                if ($this->hasBeenSuperseded()) {
                    Cache::put($this->statusKey(), 'cancelled', self::CACHE_TTL);
                } elseif ($this->attempts() >= $this->tries) {
                    $reporter->markFailed();
                    $this->clearActiveLock();
                } else {
                    // Keep ownership and an active status throughout the queue backoff.
                    Cache::put($this->statusKey(), 'queued', self::CACHE_TTL);
                }
            });

            throw $throwable;
        } finally {
            foreach ($previousConfig as $key => [$existed, $value]) {
                if ($existed) {
                    config()->set($key, $value);

                    continue;
                }

                [$root, $path] = explode('.', $key, 2);
                $rootConfig = config($root);
                if (is_array($rootConfig)) {
                    Arr::forget($rootConfig, $path);
                    config()->set($root, $rootConfig);
                }
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->duringUpdate(function () use ($exception): void {
            $reporter = new CacheProgressReporter($this->installId, redactionSecrets: InstallReportRedactor::secrets($this->inputData->toArray()));
            if ($exception instanceof Throwable) {
                $reporter->error('✗ ' . $exception->getMessage());
            }

            if ($this->hasBeenSuperseded()) {
                return;
            }

            $reporter->markFailed();
            $this->clearActiveLock();
        });
    }

    /** @phpstan-impure Reads ownership that another request or worker may change. */
    private function hasBeenSuperseded(): bool
    {
        if (Cache::get($this->statusKey()) === 'cancelled') {
            return true;
        }

        $lock = Cache::get(self::LOCK_KEY);

        if (! is_array($lock)) {
            return true;
        }

        $activeInstallId = $lock['installId'] ?? null;

        return ! is_string($activeInstallId) || $activeInstallId !== $this->installId;
    }

    private function clearActiveLock(): void
    {
        $lock = Cache::get(self::LOCK_KEY);
        if (is_array($lock) && ($lock['installId'] ?? null) === $this->installId) {
            Cache::forget(self::LOCK_KEY);
        }
    }

    /** @template T
     * @param  callable(): T  $callback
     * @return T
     */
    private function duringUpdate(callable $callback): mixed
    {
        return Cache::lock('capell.install.ownership', 30)->block(5, fn (): mixed => Cache::lock($this->stateLockKey(), 30)->block(5, $callback));
    }

    private function stateLockKey(): string
    {
        // Shared with Installer cancellation without coupling Core to Installer.
        return sprintf('capell.install.%s.state-lock', $this->installId);
    }

    private function statusKey(): string
    {
        return sprintf('capell.install.%s.status', $this->installId);
    }

    /**
     * A job serialised before the table changes existed has no such property, so it is uninitialised after unserialising.
     *
     * @return array<string, string>
     */
    private function queuedTableChanges(): array
    {
        return new ReflectionProperty($this, 'tableChanges')->isInitialized($this) ? $this->tableChanges : [];
    }
}
