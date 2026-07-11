<?php

declare(strict_types=1);

namespace Capell\Core\Jobs;

use Capell\Core\Actions\Install\RunInstallAction;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Install\CacheProgressReporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class RunCapellInstallJob implements ShouldQueue
{
    use Queueable;

    private const string LOCK_KEY = 'capell.install.lock';

    private const int CACHE_TTL = 7200;

    public int $timeout = 600;

    public function __construct(
        private readonly InstallInputData $inputData,
        private readonly string $installId,
    ) {}

    public function handle(): void
    {
        $reporter = new CacheProgressReporter($this->installId);

        if ($this->hasBeenSuperseded()) {
            Cache::put($this->statusKey(), 'cancelled', self::CACHE_TTL);
            $reporter->error('Install cancelled because another install was started.');

            return;
        }

        $reporter->markRunning();

        try {
            RunInstallAction::run($this->inputData, $reporter);
            $reporter->markComplete();
            $this->clearActiveLock();
        } catch (Throwable $throwable) {
            $reporter->error('✗ ' . $throwable->getMessage());
            $reporter->markFailed();
            Cache::forget(self::LOCK_KEY);
            throw $throwable;
        }
    }

    private function hasBeenSuperseded(): bool
    {
        $lock = Cache::get(self::LOCK_KEY);

        if (! is_array($lock)) {
            return false;
        }

        $activeInstallId = $lock['installId'] ?? null;

        return is_string($activeInstallId) && $activeInstallId !== $this->installId;
    }

    private function clearActiveLock(): void
    {
        $lock = Cache::get(self::LOCK_KEY);

        if (! is_array($lock) || ($lock['installId'] ?? null) !== $this->installId) {
            return;
        }

        Cache::forget(self::LOCK_KEY);
    }

    private function statusKey(): string
    {
        return sprintf('capell.install.%s.status', $this->installId);
    }
}
