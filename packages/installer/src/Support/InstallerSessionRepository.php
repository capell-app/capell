<?php

declare(strict_types=1);

namespace Capell\Installer\Support;

use Capell\Core\Data\InstallInputData;
use Capell\Installer\Data\ActiveInstallData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class InstallerSessionRepository
{
    public const string LOCK_KEY = 'capell.install.lock';

    public const int LOCK_TTL = 7200;

    private const array INSTALL_SESSION_CACHE_SUFFIXES = [
        'input',
        'plan',
        'status',
        'output',
        'user_id',
        'current_step',
        'completed_steps',
        'preflight',
        'success',
    ];

    public function cacheStoreIsUsable(): bool
    {
        if (config('cache.default') !== 'database') {
            return true;
        }

        try {
            return Schema::hasTable(config('cache.stores.database.table', 'cache'));
        } catch (Throwable) {
            return false;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (! $this->cacheStoreIsUsable()) {
            return $default;
        }

        try {
            return Cache::get($key, $default);
        } catch (Throwable) {
            return $default;
        }
    }

    public function has(string $key): bool
    {
        if (! $this->cacheStoreIsUsable()) {
            return false;
        }

        try {
            return Cache::has($key);
        } catch (Throwable) {
            return false;
        }
    }

    public function put(string $key, mixed $value, int $ttl = self::LOCK_TTL): void
    {
        Cache::put($key, $value, $ttl);
    }

    public function forget(string $key): void
    {
        if (! $this->cacheStoreIsUsable()) {
            return;
        }

        try {
            Cache::forget($key);
        } catch (Throwable) {
            // Cache cleanup should never hide an installer status response.
        }
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        if (! $this->cacheStoreIsUsable()) {
            return $default;
        }

        try {
            return Cache::pull($key, $default);
        } catch (Throwable) {
            return $default;
        }
    }

    public function hasActiveInstallLock(): bool
    {
        return $this->activeInstallId() !== null;
    }

    public function activeInstallId(): ?string
    {
        $lock = $this->activeInstallLock();

        return is_array($lock) ? $lock['installId'] : null;
    }

    public function activeInstallData(): ?ActiveInstallData
    {
        $lock = $this->activeInstallLock();

        if (! is_array($lock)) {
            return null;
        }

        $installId = $lock['installId'];
        $plan = $this->get($this->key($installId, 'plan'), []);

        return new ActiveInstallData(
            installId: $installId,
            status: $this->status($installId, 'running'),
            progressUrl: route('capell-installer.progress', ['installId' => $installId]),
            reportUrl: route('capell-installer.progress.download', ['installId' => $installId]),
            queued: (bool) ($lock['queued'] ?? false),
            planStepCount: is_array($plan) ? count($plan) : 0,
        );
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    public function activeInstallState(): array
    {
        $activeInstall = $this->activeInstallData();

        return $activeInstall instanceof ActiveInstallData
            ? [$activeInstall->installId, $activeInstall->status]
            : [null, 'idle'];
    }

    public function hasInstallSessionState(string $installId): bool
    {
        return array_any(self::INSTALL_SESSION_CACHE_SUFFIXES, fn (string $suffix): bool => $this->has($this->key($installId, $suffix)));
    }

    public function cancelActiveInstallBeforeStarting(string $installId): void
    {
        $lock = $this->get(self::LOCK_KEY);

        if (! is_array($lock)) {
            return;
        }

        $activeInstallId = $lock['installId'] ?? null;
        if (! is_string($activeInstallId) || $activeInstallId === $installId) {
            return;
        }

        $this->clearInstallSession($activeInstallId);
        $this->putStatus($activeInstallId, 'cancelled');
    }

    public function clearInstallSession(string $installId): void
    {
        foreach (self::INSTALL_SESSION_CACHE_SUFFIXES as $suffix) {
            $this->forget($this->key($installId, $suffix));
        }
    }

    public function lock(string $installId, bool $queued = false): void
    {
        $payload = ['installId' => $installId];

        if ($queued) {
            $payload['queued'] = true;
        }

        $this->put(self::LOCK_KEY, $payload);
    }

    public function clearActiveLock(?string $installId = null): void
    {
        if ($installId === null) {
            $this->forget(self::LOCK_KEY);

            return;
        }

        $lock = $this->get(self::LOCK_KEY);

        if (is_array($lock) && ($lock['installId'] ?? null) === $installId) {
            $this->forget(self::LOCK_KEY);
        }
    }

    public function status(string $installId, string $default = 'unknown'): string
    {
        return (string) $this->get($this->key($installId, 'status'), $default);
    }

    public function putStatus(string $installId, string $status): void
    {
        $this->put($this->key($installId, 'status'), $status);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function input(string $installId): ?array
    {
        $input = $this->get($this->key($installId, 'input'));

        return is_array($input) ? $input : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function plan(string $installId): array
    {
        $plan = $this->get($this->key($installId, 'plan'), []);

        return is_array($plan) ? array_values(array_filter($plan, is_array(...))) : [];
    }

    public function resolvedUserId(string $installId): ?int
    {
        $resolvedUserId = $this->get($this->key($installId, 'user_id'));

        return is_int($resolvedUserId) ? $resolvedUserId : null;
    }

    public function putResolvedUserId(string $installId, int $userId): void
    {
        $this->put($this->key($installId, 'user_id'), $userId);
    }

    /**
     * @param  array<int, array<string, mixed>>  $plan
     * @param  array<string, mixed>  $preflight
     */
    public function startStepInstallSession(
        string $installId,
        InstallInputData $inputData,
        array $plan,
        string $installStatus,
        ?string $firstStepKey,
        array $preflight,
    ): void {
        $this->lock($installId);
        $this->put($this->key($installId, 'input'), $inputData->toArray());
        $this->put($this->key($installId, 'plan'), $plan);
        $this->putStatus($installId, $installStatus);
        $this->put($this->key($installId, 'completed_steps'), []);

        if (is_string($firstStepKey)) {
            $this->put($this->key($installId, 'current_step'), $firstStepKey);
        } else {
            $this->forget($this->key($installId, 'current_step'));
        }

        $this->putPreflightReport($installId, $preflight);
        $this->forget($this->key($installId, 'output'));
        $this->forget($this->key($installId, 'user_id'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $plan
     */
    public function expectedStepKey(string $installId, array $plan): ?string
    {
        $cachedStepKey = $this->get($this->key($installId, 'current_step'));

        if ($cachedStepKey !== null) {
            return is_string($cachedStepKey) && $this->planContainsStep($plan, $cachedStepKey)
                ? $cachedStepKey
                : null;
        }

        $firstStepKey = $plan[0]['key'] ?? null;
        if (! is_string($firstStepKey)) {
            return null;
        }

        $this->put($this->key($installId, 'current_step'), $firstStepKey);

        return $firstStepKey;
    }

    /**
     * @return array<int, string>
     */
    public function completedSteps(string $installId): array
    {
        $completedSteps = $this->get($this->key($installId, 'completed_steps'), []);

        if (! is_array($completedSteps)) {
            return [];
        }

        return array_values(array_filter($completedSteps, is_string(...)));
    }

    public function recordCompletedStep(string $installId, string $stepKey, ?string $nextStepKey): void
    {
        $completedSteps = array_values(array_unique([
            ...$this->completedSteps($installId),
            $stepKey,
        ]));

        $this->put($this->key($installId, 'completed_steps'), $completedSteps);

        if ($nextStepKey === null) {
            $this->forget($this->key($installId, 'current_step'));

            return;
        }

        $this->put($this->key($installId, 'current_step'), $nextStepKey);
    }

    /**
     * @param  array<string, mixed>  $preflight
     */
    public function putPreflightReport(string $installId, array $preflight): void
    {
        $this->put($this->key($installId, 'preflight'), $preflight);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function preflightReport(string $installId): ?array
    {
        $preflight = $this->get($this->key($installId, 'preflight'));

        return is_array($preflight) ? $preflight : null;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function putSuccessSummary(string $installId, array $summary): void
    {
        $this->put($this->key($installId, 'success'), $summary);
    }

    public function hasSuccessSummary(string $installId): bool
    {
        return $this->has($this->key($installId, 'success'));
    }

    /**
     * @return array<string, mixed>
     */
    public function pullSuccessSummary(string $installId): array
    {
        $successSummary = $this->pull($this->key($installId, 'success'), []);

        return is_array($successSummary) ? $successSummary : [];
    }

    public function forgetSuccessSummary(string $installId): void
    {
        $this->forget($this->key($installId, 'success'));
    }

    /** @return array<int, mixed> */
    public function lines(string $installId): array
    {
        $raw = (string) $this->get($this->key($installId, 'output'), '');

        return array_values(array_filter(
            array_map(json_decode(...), explode("\n", trim($raw))),
            fn (mixed $decoded): bool => $decoded !== null && $decoded !== false,
        ));
    }

    /** @return list<string> */
    public function outputMessages(string $installId): array
    {
        $rawOutput = (string) $this->get($this->key($installId, 'output'), '');

        return array_values(collect(explode("\n", trim($rawOutput)))
            ->filter()
            ->map(function (string $line): string {
                $decodedLine = json_decode($line, true);

                return is_array($decodedLine)
                    ? (string) ($decodedLine['message'] ?? $decodedLine['line'] ?? $line)
                    : $line;
            })
            ->values()
            ->all());
    }

    public function key(string $installId, string $suffix): string
    {
        return sprintf('capell.install.%s.%s', $installId, $suffix);
    }

    /**
     * @return array{installId: string, queued?: bool}|null
     */
    private function activeInstallLock(): ?array
    {
        $lock = $this->get(self::LOCK_KEY);

        if (! is_array($lock) || ! is_string($lock['installId'] ?? null)) {
            return null;
        }

        $status = $this->status($lock['installId']);
        if (! in_array($status, ['pending', 'queued', 'running'], true)) {
            $this->forget(self::LOCK_KEY);

            return null;
        }

        $activeLock = ['installId' => $lock['installId']];

        if (($lock['queued'] ?? false) === true) {
            $activeLock['queued'] = true;
        }

        return $activeLock;
    }

    /**
     * @param  array<int, array<string, mixed>>  $plan
     */
    private function planContainsStep(array $plan, string $stepKey): bool
    {
        return array_any($plan, fn (array $planStep): bool => ($planStep['key'] ?? null) === $stepKey);
    }
}
