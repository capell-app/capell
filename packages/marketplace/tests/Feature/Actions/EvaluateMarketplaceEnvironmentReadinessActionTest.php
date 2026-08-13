<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\EvaluateMarketplaceEnvironmentReadinessAction;
use Capell\Marketplace\Contracts\MarketplaceComposerChangePublisher;
use Capell\Marketplace\Data\MarketplaceComposerPublicationRequestData;
use Capell\Marketplace\Data\MarketplaceComposerPublicationResultData;
use Capell\Marketplace\Enums\MarketplaceInstallCapability;
use Capell\Marketplace\Enums\MarketplaceReadinessStatus;
use Capell\Marketplace\Jobs\RunMarketplaceInstallAttemptJob;
use Capell\Marketplace\Support\MarketplaceQueueWorkerCommand;
use Capell\Marketplace\Support\MarketplaceWorkerHeartbeat;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    EvaluateMarketplaceEnvironmentReadinessAction::forget();
    MarketplaceWorkerHeartbeat::forget();
    configureHealthyMarketplaceHost();
});

afterEach(function (): void {
    EvaluateMarketplaceEnvironmentReadinessAction::forget();
    removeMarketplaceReadinessReleaseRoot();
});

it('reports an automated capability for a healthy single-node host', function (): void {
    $readiness = EvaluateMarketplaceEnvironmentReadinessAction::run(
        releaseRoot: marketplaceReadinessReleaseRoot(),
    );

    expect($readiness->capability)->toBe(MarketplaceInstallCapability::Automated)
        ->and($readiness->canInstallAutomatically())->toBeTrue()
        ->and($readiness->failedChecks())->toBe([])
        ->and($readiness->check('process_execution')?->status)->toBe(MarketplaceReadinessStatus::Pass);
});

it('downgrades an immutable release root to manual only', function (): void {
    config()->set('capell.release_root_mode', 'atomic');

    $readiness = EvaluateMarketplaceEnvironmentReadinessAction::run(
        releaseRoot: marketplaceReadinessReleaseRoot(),
    );

    expect($readiness->capability)->toBe(MarketplaceInstallCapability::ManualOnly)
        ->and($readiness->requiresManualInstall())->toBeTrue()
        ->and($readiness->check('release_root_writable')?->status)->toBe(MarketplaceReadinessStatus::Fail)
        ->and($readiness->check('release_root_writable')?->byDesign)->toBeTrue();
});

it('reports an immutable release root with a registered publisher as automated via deploy', function (): void {
    config()->set('capell.release_root_mode', 'atomic');
    registerMarketplaceReadinessPublisher();

    $readiness = EvaluateMarketplaceEnvironmentReadinessAction::run(
        releaseRoot: marketplaceReadinessReleaseRoot(),
    );

    expect($readiness->capability)->toBe(MarketplaceInstallCapability::AutomatedViaDeployPublisher)
        ->and($readiness->canInstallAutomatically())->toBeTrue()
        ->and($readiness->requiresManualInstall())->toBeFalse()
        ->and($readiness->check('deploy_publisher')?->status)->toBe(MarketplaceReadinessStatus::Pass);
});

it('downgrades a host without process execution to manual only', function (): void {
    $readiness = EvaluateMarketplaceEnvironmentReadinessAction::run(
        releaseRoot: marketplaceReadinessReleaseRoot(),
        processExecutionAvailable: false,
    );

    expect($readiness->capability)->toBe(MarketplaceInstallCapability::ManualOnly)
        ->and($readiness->check('process_execution')?->status)->toBe(MarketplaceReadinessStatus::Fail)
        ->and($readiness->check('process_execution')?->byDesign)->toBeTrue();
});

it('blocks a multi-node host whose cache store is node local', function (): void {
    config()->set('capell.multi_node', true);
    config()->set('cache.default', 'file');
    config()->set('cache.stores.file.driver', 'file');

    $readiness = EvaluateMarketplaceEnvironmentReadinessAction::run(
        releaseRoot: marketplaceReadinessReleaseRoot(),
    );

    expect($readiness->capability)->toBe(MarketplaceInstallCapability::Blocked)
        ->and($readiness->isBlocked())->toBeTrue()
        ->and($readiness->check('shared_cache')?->status)->toBe(MarketplaceReadinessStatus::Fail)
        ->and($readiness->check('shared_cache')?->byDesign)->toBeFalse();
});

it('blocks a host whose queue would re-dispatch a still-running install job', function (): void {
    config()->set('queue.connections.database.retry_after', RunMarketplaceInstallAttemptJob::jobTimeoutSeconds() - 60);

    $readiness = EvaluateMarketplaceEnvironmentReadinessAction::run(
        releaseRoot: marketplaceReadinessReleaseRoot(),
    );

    expect($readiness->capability)->toBe(MarketplaceInstallCapability::Blocked)
        ->and($readiness->check('timeout_chain')?->status)->toBe(MarketplaceReadinessStatus::Fail)
        ->and($readiness->check('timeout_chain')?->byDesign)->toBeFalse();
});

it('warns rather than fails when Composer cannot be resolved', function (): void {
    $readiness = EvaluateMarketplaceEnvironmentReadinessAction::run(
        releaseRoot: marketplaceReadinessReleaseRoot(),
        composerBinaryResolvable: false,
    );

    expect($readiness->capability)->toBe(MarketplaceInstallCapability::Automated)
        ->and($readiness->check('composer_binary')?->status)->toBe(MarketplaceReadinessStatus::Warn);
});

it('warns when a configured binary is wrong even though resolution falls back past it', function (): void {
    // On a Marketplace-only host this report is the operator's only sight of
    // the mistake — the installer preflight they never run is the other one.
    config()->set('capell.process.php_binary', '/nonexistent/capell/php');
    config()->set('capell.process.composer_binary', '/nonexistent/capell/composer');
    EvaluateMarketplaceEnvironmentReadinessAction::forget();

    $readiness = EvaluateMarketplaceEnvironmentReadinessAction::run(
        releaseRoot: marketplaceReadinessReleaseRoot(),
        phpBinaryResolvable: true,
        composerBinaryResolvable: true,
    );

    expect($readiness->capability)->toBe(MarketplaceInstallCapability::Automated)
        ->and($readiness->check('php_binary')?->status)->toBe(MarketplaceReadinessStatus::Warn)
        ->and($readiness->check('php_binary')?->message)->toContain('/nonexistent/capell/php')
        ->and($readiness->check('composer_binary')?->status)->toBe(MarketplaceReadinessStatus::Warn)
        ->and($readiness->check('composer_binary')?->message)->toContain('/nonexistent/capell/composer');
});

it('warns about the queue worker while no heartbeat has been seen', function (): void {
    $queueWorker = EvaluateMarketplaceEnvironmentReadinessAction::run(
        releaseRoot: marketplaceReadinessReleaseRoot(),
    )->check('queue_worker');

    expect($queueWorker?->status)->toBe(MarketplaceReadinessStatus::Warn)
        ->and($queueWorker?->failed())->toBeFalse()
        ->and($queueWorker?->remediation)->toContain(MarketplaceQueueWorkerCommand::forInstallation());
});

it('passes the queue worker check once a worker heartbeat has landed', function (): void {
    MarketplaceWorkerHeartbeat::record();
    EvaluateMarketplaceEnvironmentReadinessAction::forget();

    $readiness = EvaluateMarketplaceEnvironmentReadinessAction::run(
        releaseRoot: marketplaceReadinessReleaseRoot(),
    );

    expect($readiness->check('queue_worker')?->status)->toBe(MarketplaceReadinessStatus::Pass)
        ->and($readiness->capability)->toBe(MarketplaceInstallCapability::Automated);
});

it('warns rather than fails when the marketplace queue runs synchronously', function (): void {
    config()->set('capell-marketplace.marketplace.operations_queue_connection', 'sync');
    config()->set('queue.connections.sync.driver', 'sync');
    EvaluateMarketplaceEnvironmentReadinessAction::forget();

    $readiness = EvaluateMarketplaceEnvironmentReadinessAction::run(
        releaseRoot: marketplaceReadinessReleaseRoot(),
    );

    // Synchronous installs block the request instead of never happening, so
    // this is a degraded mode rather than a broken host.
    expect($readiness->check('queue_worker')?->status)->toBe(MarketplaceReadinessStatus::Warn)
        ->and($readiness->check('queue_worker')?->failed())->toBeFalse()
        ->and($readiness->capability)->toBe(MarketplaceInstallCapability::Automated);
});

it('gives every failing and warning check remediation text and a docs anchor', function (): void {
    config()->set('capell.release_root_mode', 'atomic');
    config()->set('capell.multi_node', true);
    config()->set('cache.default', 'file');
    config()->set('cache.stores.file.driver', 'file');
    config()->set('queue.connections.database.retry_after', 60);

    $readiness = EvaluateMarketplaceEnvironmentReadinessAction::run(
        releaseRoot: marketplaceReadinessReleaseRoot(),
        processExecutionAvailable: false,
        phpBinaryResolvable: false,
        composerBinaryResolvable: false,
    );

    $reported = [...$readiness->failedChecks(), ...$readiness->warnedChecks()];

    expect($reported)->not->toBe([]);

    foreach ($reported as $check) {
        expect($check->remediation)->not->toBeNull()
            ->and($check->remediation)->not->toBe('')
            ->and($check->docsAnchor)->toMatch('/^[a-z0-9]+(-[a-z0-9]+)*$/');
    }
});

it('caches the evaluation for a short window and can be forgotten', function (): void {
    $releaseRoot = marketplaceReadinessReleaseRoot();
    $first = EvaluateMarketplaceEnvironmentReadinessAction::run(releaseRoot: $releaseRoot);

    config()->set('capell.release_root_mode', 'atomic');

    expect(EvaluateMarketplaceEnvironmentReadinessAction::run(releaseRoot: $releaseRoot)->capability)
        ->toBe($first->capability);

    EvaluateMarketplaceEnvironmentReadinessAction::forget();

    expect(EvaluateMarketplaceEnvironmentReadinessAction::run(releaseRoot: $releaseRoot)->capability)
        ->toBe(MarketplaceInstallCapability::ManualOnly);
});

it('caches scalar data and repairs legacy object entries when classes cannot be unserialized', function (): void {
    $originalCache = Cache::getFacadeRoot();
    $store = new ArrayStore(serializesValues: true, serializableClasses: false);
    Cache::swap(new Repository($store));

    try {
        $releaseRoot = marketplaceReadinessReleaseRoot();
        $first = EvaluateMarketplaceEnvironmentReadinessAction::run(releaseRoot: $releaseRoot);
        $cacheKey = collect(array_keys($store->all(unserialize: false)))
            ->first(static fn (string $key): bool => str_starts_with(
                $key,
                EvaluateMarketplaceEnvironmentReadinessAction::CACHE_KEY . ':',
            ));

        expect($cacheKey)->toBeString()
            ->and(Cache::get($cacheKey))->toBeArray();

        Cache::put($cacheKey, $first, EvaluateMarketplaceEnvironmentReadinessAction::CACHE_SECONDS);

        expect(Cache::get($cacheKey))->toBeInstanceOf(__PHP_Incomplete_Class::class);

        $recovered = EvaluateMarketplaceEnvironmentReadinessAction::run(releaseRoot: $releaseRoot);

        expect($recovered->capability)->toBe(MarketplaceInstallCapability::Automated)
            ->and($recovered->check('process_execution')?->status)->toBe(MarketplaceReadinessStatus::Pass)
            ->and(Cache::get($cacheKey))->toBeArray();
    } finally {
        Cache::swap($originalCache);
    }
});

function configureHealthyMarketplaceHost(): void
{
    config()->set('capell.release_root_mode', 'mutable');
    config()->set('capell.server_side_tooling', true);
    config()->set('capell.multi_node', false);
    config()->set('capell-marketplace.marketplace.operations_queue_connection', 'database');
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.retry_after', 1800);
}

/**
 * A directly addressed, writable release root. The test suite's own base path
 * lives under vendor/, which may itself be reached through a symlink, so the
 * release-root checks need a root the test owns.
 */
function marketplaceReadinessReleaseRoot(): string
{
    $root = marketplaceReadinessReleaseRootPath();

    if (! is_dir($root)) {
        mkdir($root, 0755, true);
        touch($root . '/composer.json');
    }

    return $root;
}

function removeMarketplaceReadinessReleaseRoot(): void
{
    $root = marketplaceReadinessReleaseRootPath();

    if (! is_dir($root)) {
        return;
    }

    if (is_file($root . '/composer.json')) {
        unlink($root . '/composer.json');
    }

    rmdir($root);
}

function marketplaceReadinessReleaseRootPath(): string
{
    $temporaryRoot = realpath(sys_get_temp_dir());

    throw_unless(is_string($temporaryRoot), RuntimeException::class, 'The system temporary directory must resolve to a canonical path.');

    return $temporaryRoot . '/capell-marketplace-readiness-root';
}

function registerMarketplaceReadinessPublisher(): void
{
    app()->bind('test.marketplace.readiness-publisher', fn (): MarketplaceComposerChangePublisher => new class implements MarketplaceComposerChangePublisher
    {
        public function publish(MarketplaceComposerPublicationRequestData $request): MarketplaceComposerPublicationResultData
        {
            return new MarketplaceComposerPublicationResultData(commitSha: 'deadbeef');
        }
    });

    app()->tag(['test.marketplace.readiness-publisher'], MarketplaceComposerChangePublisher::TAG);
}
