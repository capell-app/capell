<?php

declare(strict_types=1);

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Health\CoreCacheHealthCheck;
use Capell\Core\Support\Cache\CapellCacheManager;

it('exposes cache enablement reachability and safe runtime evidence', function (): void {
    config([
        'cache.default' => 'array',
        'capell.disable_cache' => false,
    ]);

    resolve(CapellCacheManager::class)->rememberCache('health-check-secret-key', fn (): string => 'value');

    $results = CoreCacheHealthCheck::runDiagnostics();
    $manifestResults = CoreCacheHealthCheck::runDiagnostics('core.cache-runtime');
    $backend = CoreCacheHealthCheck::runDiagnostics('core.cache-backend')->sole();

    expect($results)->toHaveCount(2)
        ->and($manifestResults)->toHaveCount(2)
        ->and($results->every(fn (DoctorCheckResultData $result): bool => $result->passed))->toBeTrue()
        ->and($backend->id)->toBe('core.cache-backend')
        ->and($backend->evidence['backend_reachable'])->toBeTrue()
        ->and($backend->evidence['sampled_key_hashes'])->not->toContain('health-check-secret-key')
        ->and(CoreCacheHealthCheck::runDiagnostics('core.unknown'))->toBeEmpty();
});

it('reports disabled cache configuration as unhealthy', function (): void {
    config(['capell.disable_cache' => true]);

    $result = CoreCacheHealthCheck::runDiagnostics('core.cache-enabled')->sole();

    expect($result->passed)->toBeFalse()
        ->and($result->remediation)->not->toBeNull();
});
