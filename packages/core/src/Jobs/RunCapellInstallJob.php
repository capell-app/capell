<?php

declare(strict_types=1);

namespace Capell\Core\Jobs;

use Capell\Core\Actions\Install\RunInstallAction;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Install\CacheProgressReporter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class RunCapellInstallJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const string LOCK_KEY = 'capell.install.lock';

    private const int CACHE_TTL = 7200;

    public int $timeout = 600;

    public int $tries = 3;

    public int $uniqueFor = self::CACHE_TTL;

    public function __construct(
        private readonly InstallInputData $inputData,
        private readonly string $installId,
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
        $reporter = new CacheProgressReporter($this->installId);

        $admitted = Cache::lock($this->stateLockKey(), 30)->block(5, function () use ($reporter): bool {
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

        try {
            RunInstallAction::run($this->inputData, $reporter);
            Cache::lock($this->stateLockKey(), 30)->block(5, function () use ($reporter): void {
                if ($this->hasBeenSuperseded()) {
                    Cache::put($this->statusKey(), 'cancelled', self::CACHE_TTL);

                    return;
                }

                $reporter->markComplete();
                $this->clearActiveLock();
            });
        } catch (Throwable $throwable) {
            Cache::lock($this->stateLockKey(), 30)->block(5, function () use ($reporter, $throwable): void {
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
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cache::lock($this->stateLockKey(), 30)->block(5, function (): void {
            if ($this->hasBeenSuperseded()) {
                return;
            }

            new CacheProgressReporter($this->installId)->markFailed();
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
        Cache::lock('capell.install.ownership', 30)->block(5, function (): void {
            $lock = Cache::get(self::LOCK_KEY);

            if (! is_array($lock) || ($lock['installId'] ?? null) !== $this->installId) {
                return;
            }

            Cache::forget(self::LOCK_KEY);
        });
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
}
