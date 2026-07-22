<?php

declare(strict_types=1);

namespace Capell\Core\Health;

use Capell\Core\Contracts\Extensions\ChecksExtensionHealth;
use Capell\Core\Data\Cache\CacheRuntimeDiagnosticsData;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Support\Cache\CapellCacheManager;
use Illuminate\Support\Collection;

final class CoreCacheHealthCheck implements ChecksExtensionHealth
{
    private const string RUNTIME_KEY = 'core.cache-runtime';

    public static function compatibleCapellApiVersion(): string
    {
        return '^1.0';
    }

    /**
     * @return Collection<int, DoctorCheckResultData>
     */
    public static function runDiagnostics(?string $key = null): Collection
    {
        $diagnostics = resolve(CapellCacheManager::class)->runtimeDiagnostics();
        $checks = collect([
            'core.cache-enabled' => self::enabledCheck($diagnostics),
            'core.cache-backend' => self::backendCheck($diagnostics),
        ]);

        if ($key === null || $key === self::RUNTIME_KEY) {
            return $checks->values();
        }

        $check = $checks->get($key);

        return $check instanceof DoctorCheckResultData ? collect([$check]) : collect();
    }

    public static function passed(): bool
    {
        return self::runDiagnostics()
            ->every(static fn (DoctorCheckResultData $result): bool => $result->passed);
    }

    private static function enabledCheck(CacheRuntimeDiagnosticsData $diagnostics): DoctorCheckResultData
    {
        return new DoctorCheckResultData(
            id: 'core.cache-enabled',
            label: (string) __('capell-core::health.cache.enabled.label'),
            passed: $diagnostics->enabled,
            message: $diagnostics->enabled
                ? (string) __('capell-core::health.cache.enabled.passed')
                : (string) __('capell-core::health.cache.enabled.failed'),
            remediation: $diagnostics->enabled
                ? null
                : (string) __('capell-core::health.cache.enabled.remediation'),
            evidence: self::evidence($diagnostics),
        );
    }

    private static function backendCheck(CacheRuntimeDiagnosticsData $diagnostics): DoctorCheckResultData
    {
        return new DoctorCheckResultData(
            id: 'core.cache-backend',
            label: (string) __('capell-core::health.cache.backend.label'),
            passed: $diagnostics->backendReachable,
            message: $diagnostics->backendReachable
                ? (string) __('capell-core::health.cache.backend.passed', [
                    'store' => $diagnostics->store,
                    'driver' => $diagnostics->driver,
                ])
                : (string) __('capell-core::health.cache.backend.failed', [
                    'store' => $diagnostics->store,
                    'driver' => $diagnostics->driver,
                ]),
            remediation: $diagnostics->backendReachable
                ? null
                : (string) __('capell-core::health.cache.backend.remediation'),
            evidence: self::evidence($diagnostics),
        );
    }

    /** @return array<string, bool|int|string|list<string>> */
    private static function evidence(CacheRuntimeDiagnosticsData $diagnostics): array
    {
        return [
            'enabled' => $diagnostics->enabled,
            'backend_reachable' => $diagnostics->backendReachable,
            'store' => $diagnostics->store,
            'driver' => $diagnostics->driver,
            'hits' => $diagnostics->hitCount,
            'misses' => $diagnostics->missCount,
            'fills' => $diagnostics->fillCount,
            'backend_failures' => $diagnostics->backendFailureCount,
            'sampled_key_hashes' => $diagnostics->sampledKeyHashes,
            'activity_window_seconds' => 86400,
        ];
    }
}
