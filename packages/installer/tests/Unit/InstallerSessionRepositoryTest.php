<?php

declare(strict_types=1);

use Capell\Core\Data\InstallInputData;
use Capell\Installer\Support\InstallerSessionRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

it('fails closed when the database cache store is selected before the cache table exists', function (): void {
    config()->set('cache.default', 'database');
    config()->set('cache.stores.database.table', 'missing_installer_cache_table');

    $repository = new InstallerSessionRepository;

    expect($repository->cacheStoreIsUsable())->toBeFalse()
        ->and($repository->get('capell.install.example', 'fallback'))->toBe('fallback')
        ->and($repository->has('capell.install.example'))->toBeFalse()
        ->and($repository->pull('capell.install.example', 'fallback'))->toBe('fallback')
        ->and($repository->hasActiveInstallLock())->toBeFalse()
        ->and($repository->hasInstallSessionState('11111111-1111-4111-a111-111111111111'))->toBeFalse();

    $repository->forget('capell.install.example');
});

it('fails closed when database cache table checks throw', function (): void {
    $originalDefaultConnection = config('database.default');

    config([
        'database.default' => 'installer_session_missing_connection',
        'database.connections.installer_session_missing_connection' => [
            'driver' => 'sqlite',
            'database' => '/sys/capell-missing/cache.sqlite',
            'prefix' => '',
        ],
        'cache.default' => 'database',
        'cache.stores.database.table' => 'cache',
    ]);

    DB::purge('installer_session_missing_connection');

    try {
        expect((new InstallerSessionRepository)->cacheStoreIsUsable())->toBeFalse();
    } finally {
        config(['database.default' => $originalDefaultConnection]);
        DB::purge('installer_session_missing_connection');
        DB::purge(is_string($originalDefaultConnection) ? $originalDefaultConnection : null);
    }
});

it('turns cache facade exceptions into safe installer session fallbacks', function (): void {
    config(['cache.default' => 'array']);

    Cache::shouldReceive('get')
        ->with('capell.install.key', 'fallback')
        ->andThrow(new RuntimeException('cache get failed'));
    Cache::shouldReceive('has')
        ->with('capell.install.key')
        ->andThrow(new RuntimeException('cache has failed'));
    Cache::shouldReceive('forget')
        ->with('capell.install.key')
        ->andThrow(new RuntimeException('cache forget failed'));
    Cache::shouldReceive('pull')
        ->with('capell.install.key', 'fallback')
        ->andThrow(new RuntimeException('cache pull failed'));

    $repository = new InstallerSessionRepository;

    expect($repository->get('capell.install.key', 'fallback'))->toBe('fallback')
        ->and($repository->has('capell.install.key'))->toBeFalse()
        ->and($repository->pull('capell.install.key', 'fallback'))->toBe('fallback');

    $repository->forget('capell.install.key');
});

it('does not cancel the install that is about to start', function (): void {
    config(['cache.default' => 'array']);

    $repository = new InstallerSessionRepository;

    Cache::put(InstallerSessionRepository::LOCK_KEY, ['installId' => 'current-install']);
    Cache::put('capell.install.current-install.status', 'running');
    Cache::put('capell.install.current-install.output', json_encode(['message' => 'still running']));

    $repository->cancelActiveInstallBeforeStarting('current-install');

    expect(Cache::get('capell.install.current-install.status'))->toBe('running')
        ->and($repository->hasInstallSessionState('current-install'))->toBeTrue();
});

it('formats install output messages through the session repository', function (): void {
    config(['cache.default' => 'array']);

    $repository = new InstallerSessionRepository;
    $installId = 'output-install';

    Cache::put($repository->key($installId, 'output'), implode("\n", [
        json_encode(['message' => 'Preparing database'], JSON_THROW_ON_ERROR),
        json_encode(['line' => 'Publishing migrations'], JSON_THROW_ON_ERROR),
        'Plain install output',
    ]));

    expect($repository->outputMessages($installId))->toBe([
        'Preparing database',
        'Publishing migrations',
        'Plain install output',
    ]);
});

it('resolves active install data and clears stale locks', function (): void {
    config(['cache.default' => 'array']);

    $repository = new InstallerSessionRepository;
    $installId = '11111111-1111-4111-a111-111111111111';

    $repository->lock($installId, queued: true);
    $repository->putStatus($installId, 'queued');
    Cache::put($repository->key($installId, 'plan'), ['prepare', 'install']);

    $activeInstall = $repository->activeInstallData();

    expect($activeInstall)->not->toBeNull()
        ->and($activeInstall?->installId)->toBe($installId)
        ->and($activeInstall?->status)->toBe('queued')
        ->and($activeInstall?->queued)->toBeTrue()
        ->and($activeInstall?->planStepCount)->toBe(2)
        ->and($repository->activeInstallState())->toBe([$installId, 'queued']);

    $repository->putStatus($installId, 'failed');

    expect($repository->hasActiveInstallLock())->toBeFalse()
        ->and(Cache::get(InstallerSessionRepository::LOCK_KEY))->toBeNull();
});

it('owns browser step session state transitions', function (): void {
    config(['cache.default' => 'array']);

    $repository = new InstallerSessionRepository;
    $installId = '22222222-2222-4222-a222-222222222222';
    $plan = [
        ['key' => 'preflight-checks', 'label' => 'Preflight checks'],
        ['key' => 'prepare-environment', 'label' => 'Prepare environment'],
    ];

    $repository->startStepInstallSession(
        installId: $installId,
        inputData: new InstallInputData(
            siteUrl: 'https://example.com',
            packages: [],
            languages: ['en'],
            demoContent: false,
            cachesToClear: [],
            generateSitemap: false,
            generateStaticSite: false,
        ),
        plan: $plan,
        installStatus: 'pending',
        firstStepKey: 'preflight-checks',
        preflight: ['status' => 'ok'],
    );

    expect($repository->input($installId))->toMatchArray(['siteUrl' => 'https://example.com'])
        ->and($repository->plan($installId))->toBe($plan)
        ->and($repository->status($installId))->toBe('pending')
        ->and($repository->expectedStepKey($installId, $plan))->toBe('preflight-checks')
        ->and($repository->preflightReport($installId))->toBe(['status' => 'ok'])
        ->and($repository->completedSteps($installId))->toBe([]);

    $repository->recordCompletedStep($installId, 'preflight-checks', 'prepare-environment');

    expect($repository->completedSteps($installId))->toBe(['preflight-checks'])
        ->and($repository->expectedStepKey($installId, $plan))->toBe('prepare-environment');

    $repository->recordCompletedStep($installId, 'prepare-environment', null);

    expect($repository->completedSteps($installId))->toBe(['preflight-checks', 'prepare-environment'])
        ->and($repository->expectedStepKey($installId, $plan))->toBe('preflight-checks');
});

it('stores and pulls install success summaries through named session methods', function (): void {
    config(['cache.default' => 'array']);

    $repository = new InstallerSessionRepository;
    $installId = '33333333-3333-4333-a333-333333333333';

    $repository->putSuccessSummary($installId, [
        'primaryAdmin' => 'Admin <admin@example.com>',
        'roleUsersCreated' => true,
    ]);

    expect($repository->hasSuccessSummary($installId))->toBeTrue()
        ->and($repository->pullSuccessSummary($installId))->toBe([
            'primaryAdmin' => 'Admin <admin@example.com>',
            'roleUsersCreated' => true,
        ])
        ->and($repository->hasSuccessSummary($installId))->toBeFalse();
});
