<?php

declare(strict_types=1);

use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Install\CacheProgressReporter;
use Capell\Core\Support\Install\FileLogProgressReporter;
use Capell\Installer\Enums\InstallerRunMode;
use Capell\Installer\Enums\InstallerRunStatus;
use Capell\Installer\Support\InstallerRun;
use Capell\Installer\Support\InstallerSessionRepository;
use Illuminate\Support\Facades\Cache;

function installerAggregateInput(): InstallInputData
{
    return new InstallInputData(
        siteUrl: 'https://example.com',
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
    );
}

it('keeps the existing persisted status vocabulary and classifications', function (): void {
    expect(array_column(InstallerRunStatus::cases(), 'value'))->toBe([
        'unknown', 'idle', 'pending', 'queued', 'running', 'complete', 'failed', 'cancelled',
    ]);

    foreach (InstallerRunStatus::cases() as $status) {
        expect($status->isActive())->toBe(in_array($status->value, ['pending', 'queued', 'running'], true))
            ->and($status->isTerminal())->toBe(in_array($status->value, ['complete', 'failed', 'cancelled'], true));
    }
});

it('permits every previously unrestricted status transition', function (InstallerRunStatus $source, string $method, InstallerRunStatus $target): void {
    config(['cache.default' => 'array']);
    $sessions = new InstallerSessionRepository;
    $run = new InstallerRun($sessions, 'transition-install');

    // Seed historical cache values independently of the API being verified.
    Cache::put($sessions->key($run->installId, 'status'), $source->value);
    Cache::put(InstallerSessionRepository::LOCK_KEY, ['installId' => $run->installId]);
    $run->{$method}();

    expect($run->status())->toBe($target)
        ->and($sessions->status($run->installId))->toBe($target->value)
        ->and(Cache::get($sessions->key($run->installId, 'status')))->toBe($target->value)
        ->and(Cache::get(InstallerSessionRepository::LOCK_KEY))->toBe(
            in_array($method, ['markFailed', 'cancel'], true) ? null : ['installId' => $run->installId],
        );
})->with(InstallerRunStatus::cases())->with([
    'running' => ['markRunning', InstallerRunStatus::Running],
    'complete' => ['markComplete', InstallerRunStatus::Complete],
    'failed' => ['markFailed', InstallerRunStatus::Failed],
    'cancelled' => ['cancel', InstallerRunStatus::Cancelled],
]);

it('reads current shared state instead of retaining a snapshot', function (): void {
    config(['cache.default' => 'array']);
    $sessions = new InstallerSessionRepository;
    $run = new InstallerRun($sessions, 'shared-install');

    expect($run->status())->toBe(InstallerRunStatus::Unknown)
        ->and($run->status(InstallerRunStatus::Pending))->toBe(InstallerRunStatus::Pending);

    $reporter = new CacheProgressReporter($run->installId);
    $reporter->markRunning();

    expect($run->status())->toBe(InstallerRunStatus::Running);
    $reporter->markComplete();
    expect($run->status())->toBe(InstallerRunStatus::Complete);
});

it('delegates status reporting without adding a second cache write', function (string $method, InstallerRunStatus $target): void {
    config(['cache.default' => 'array']);
    $sessions = new InstallerSessionRepository;
    $run = new InstallerRun($sessions, 'reported-install');
    $reporter = new FileLogProgressReporter($run->installId, new CacheProgressReporter($run->installId));
    Cache::shouldReceive('put')
        ->with($sessions->key($run->installId, 'status'), $target->value, InstallerSessionRepository::LOCK_TTL)
        ->once()
        ->andReturn(true);

    if ($target === InstallerRunStatus::Failed) {
        Cache::shouldReceive('get')->with(InstallerSessionRepository::LOCK_KEY, null)->once()->andReturn(null);
    }

    $run->{$method}($reporter);

    expect(file_get_contents($reporter->logPath()))->toContain('[STATUS] ' . $target->value);
})->with([
    ['markRunning', InstallerRunStatus::Running],
    ['markComplete', InstallerRunStatus::Complete],
    ['markFailed', InstallerRunStatus::Failed],
]);

it('preserves reporter cache values and file status entries', function (string $method, InstallerRunStatus $target): void {
    config(['cache.default' => 'array']);
    $sessions = new InstallerSessionRepository;
    $run = new InstallerRun($sessions, 'file-reported-install');
    $reporter = new FileLogProgressReporter($run->installId, new CacheProgressReporter($run->installId));

    $run->{$method}($reporter);

    expect($sessions->status($run->installId))->toBe($target->value)
        ->and(file_get_contents($reporter->logPath()))->toContain('[STATUS] ' . $target->value);
})->with([
    ['markRunning', InstallerRunStatus::Running],
    ['markComplete', InstallerRunStatus::Complete],
    ['markFailed', InstallerRunStatus::Failed],
]);

it('starts queued and synchronous runs from every persisted state', function (InstallerRunStatus $source, bool $queued): void {
    config(['cache.default' => 'array']);
    $sessions = new InstallerSessionRepository;
    $run = new InstallerRun($sessions, 'start-install');
    Cache::put($sessions->key($run->installId, 'status'), $source->value);

    if ($queued) {
        $run->startQueued();
    } else {
        $run->startSynchronous();
    }

    expect($run->status())->toBe($queued ? InstallerRunStatus::Queued : InstallerRunStatus::Running)
        ->and(Cache::get(InstallerSessionRepository::LOCK_KEY))->toBe(
            $queued ? ['installId' => $run->installId, 'queued' => true] : ['installId' => $run->installId],
        );
})->with(InstallerRunStatus::cases())->with([true, false]);

it('starts browser plans from every persisted state', function (InstallerRunStatus $source, bool $empty): void {
    config(['cache.default' => 'array']);
    $sessions = new InstallerSessionRepository;
    $run = new InstallerRun($sessions, 'browser-install');
    Cache::put($sessions->key($run->installId, 'status'), $source->value);
    Cache::put($sessions->key($run->installId, 'current_step'), 'obsolete-step');
    $plan = $empty ? [] : [['key' => 'prepare-environment', 'label' => 'Prepare environment']];

    $run->startBrowserSteps(installerAggregateInput(), $plan, $empty ? null : 'prepare-environment', ['checks' => []]);

    expect($run->status())->toBe($empty ? InstallerRunStatus::Complete : InstallerRunStatus::Pending)
        ->and($sessions->input($run->installId))->toMatchArray(['siteUrl' => 'https://example.com'])
        ->and($sessions->plan($run->installId))->toBe($plan)
        ->and($sessions->get($sessions->key($run->installId, 'current_step')))->toBe($empty ? null : 'prepare-environment')
        ->and($sessions->completedSteps($run->installId))->toBe([])
        ->and($sessions->stepDiagnostics($run->installId))->toBe([])
        ->and($sessions->preflightReport($run->installId))->toBe(['checks' => []])
        ->and(Cache::get(InstallerSessionRepository::LOCK_KEY))->toBe(['installId' => $run->installId]);
})->with(InstallerRunStatus::cases())->with([true, false]);

it('cancels the different active run before starting each mode', function (InstallerRunMode $mode): void {
    config(['cache.default' => 'array']);
    $sessions = new InstallerSessionRepository;
    $previous = new InstallerRun($sessions, 'previous-install');
    $previous->startBrowserSteps(installerAggregateInput(), [['key' => 'prepare']], 'prepare', ['checks' => []]);

    $sessions->putRecommendation($previous->installId, ['key' => 'blog']);
    $sessions->putSuccessSummary($previous->installId, ['primaryAdmin' => null]);
    $sessions->put($sessions->key($previous->installId, 'output'), 'previous output');

    $next = new InstallerRun($sessions, 'next-install');

    match ($mode) {
        InstallerRunMode::Queued => $next->startQueued(),
        InstallerRunMode::Synchronous => $next->startSynchronous(),
        InstallerRunMode::BrowserSteps => $next->startBrowserSteps(installerAggregateInput(), [['key' => 'prepare']], 'prepare', []),
    };

    expect($previous->status())->toBe(InstallerRunStatus::Cancelled)
        ->and($sessions->input($previous->installId))->toBeNull()
        ->and($sessions->plan($previous->installId))->toBe([])
        ->and($sessions->preflightReport($previous->installId))->toBeNull()
        ->and($sessions->recommendation($previous->installId))->toBeNull()
        ->and($sessions->hasSuccessSummary($previous->installId))->toBeFalse()
        ->and($sessions->get($sessions->key($previous->installId, 'output')))->toBeNull()
        ->and($sessions->activeInstallId())->toBe($next->installId);
})->with(InstallerRunMode::cases());

it('preserves a same-run session when restarting without a browser plan', function (): void {
    config(['cache.default' => 'array']);
    $sessions = new InstallerSessionRepository;
    $run = new InstallerRun($sessions, 'same-install');
    $run->startQueued();

    $sessions->putRecommendation($run->installId, ['key' => 'blog']);
    $run->startSynchronous();

    expect($run->status())->toBe(InstallerRunStatus::Running)
        ->and($sessions->recommendation($run->installId))->toBe(['key' => 'blog'])
        ->and(Cache::get(InstallerSessionRepository::LOCK_KEY))->toBe(['installId' => $run->installId]);
});

it('preserves the foreign active lock on failure cancellation and explicit release', function (string $method): void {
    config(['cache.default' => 'array']);
    $sessions = new InstallerSessionRepository;
    $stale = new InstallerRun($sessions, 'stale-install');
    $stale->markRunning();

    $active = new InstallerRun($sessions, 'active-install');
    $active->startQueued();

    $stale->{$method}();

    expect($sessions->activeInstallId())->toBe($active->installId)
        ->and(Cache::get(InstallerSessionRepository::LOCK_KEY))->toBe(['installId' => $active->installId, 'queued' => true]);
})->with(['markFailed', 'cancel', 'releaseLock']);

it('keeps the replacement lock until the incoming run overwrites it', function (): void {
    config(['cache.default' => 'array']);
    $sessions = new InstallerSessionRepository;
    $previous = new InstallerRun($sessions, 'previous-install');
    $previous->startQueued();

    $sessions->cancelActiveInstallBeforeStarting('incoming-install');

    expect($previous->status())->toBe(InstallerRunStatus::Cancelled)
        ->and(Cache::get(InstallerSessionRepository::LOCK_KEY))->toBe(['installId' => $previous->installId, 'queued' => true]);
});

it('cancels session data idempotently while keeping the persisted cancelled status', function (): void {
    config(['cache.default' => 'array']);
    $sessions = new InstallerSessionRepository;
    $run = new InstallerRun($sessions, 'cancel-install');
    $run->startBrowserSteps(installerAggregateInput(), [['key' => 'prepare']], 'prepare', ['checks' => []]);

    $sessions->putResolvedUserId($run->installId, 42);
    $sessions->putPackageMetadataRefreshed($run->installId, true);
    $sessions->recordCompletedStep($run->installId, 'earlier', 'prepare');
    $sessions->recordStepPeakMemory($run->installId, 'earlier', 1024);
    $sessions->putSuccessSummary($run->installId, ['primaryAdmin' => null]);
    $sessions->putRecommendation($run->installId, ['key' => 'blog']);
    $sessions->put($sessions->key($run->installId, 'output'), 'previous output');

    $run->cancel();
    $run->cancel();

    expect($run->status())->toBe(InstallerRunStatus::Cancelled)
        ->and(Cache::get(InstallerSessionRepository::LOCK_KEY))->toBeNull();
    foreach (['input', 'plan', 'output', 'user_id', 'package_metadata_refreshed', 'current_step', 'completed_steps', 'preflight', 'success', 'diagnostics', 'recommendation'] as $suffix) {
        expect($sessions->has($sessions->key($run->installId, $suffix)))->toBeFalse();
    }
});

it('retains the existing lock and status lifetime', function (): void {
    config(['cache.default' => 'array']);
    $sessions = new InstallerSessionRepository;
    $run = new InstallerRun($sessions, 'expiry-install');
    $run->startQueued();

    $this->travel(InstallerSessionRepository::LOCK_TTL - 1)->seconds();
    expect($run->status())->toBe(InstallerRunStatus::Queued)
        ->and($sessions->activeInstallId())->toBe($run->installId);

    $this->travel(1)->seconds();
    expect($run->status())->toBe(InstallerRunStatus::Unknown)
        ->and(Cache::get(InstallerSessionRepository::LOCK_KEY))->toBeNull();
});
