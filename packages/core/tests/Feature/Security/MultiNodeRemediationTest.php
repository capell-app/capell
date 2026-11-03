<?php

declare(strict_types=1);

use Capell\Core\Actions\Security\ApplyApprovedSecurityRemediationsAction;
use Capell\Core\Actions\Security\BuildSecurityPostureInventoryAction;
use Capell\Core\Actions\Security\EvaluatePendingSecurityRemediationsAction;
use Capell\Core\Actions\Security\RunSecurityWeaknessScanAction;
use Capell\Core\Contracts\Security\SecurityWeaknessAnalyser;
use Capell\Core\Data\Security\SecurityPostureInventoryData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Enums\Security\SecurityFindingStatus;
use Capell\Core\Enums\Security\SecurityRemediationOutcome;
use Capell\Core\Events\SecurityRemediationApplied;
use Capell\Core\Models\SecurityRemediationApproval;
use Capell\Core\Models\SecurityRemediationRun;
use Capell\Core\Models\SecurityScanRun;
use Capell\Core\Models\SecurityWeaknessFinding;
use Capell\Core\Support\Diagnostics\Checks\SecurityPostureCheck;
use Capell\Core\Support\Security\Checks\DebugEnabledCheck;
use Capell\Core\Support\Security\Remediations\DebugEnabledRemediation;
use Capell\Core\Support\Security\Remediations\EnvironmentRemediation;
use Capell\Core\Support\Security\SecurityNodeRegistry;
use Capell\Core\Support\Security\SecurityWeaknessRegistry;
use Capell\Core\Tests\Support\Security\FakeConfigClearCommand;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Every "node" in these tests reads the same in-memory store, standing in for
 * Redis; the production guard still refuses the array driver itself.
 */
function multiNodeScanId(): string
{
    $id = SecurityWeaknessFinding::query()->firstOrFail()->last_detected_scan_id;
    SecurityWeaknessFinding::query()->update(['last_detected_scan_id' => $id]);
    SecurityScanRun::query()->firstOrCreate(['id' => $id], ['started_at' => now(), 'finished_at' => now(), 'findings_count' => 1, 'source' => 'system:console']);

    return (string) $id;
}

function useSharedNodeCache(): void
{
    $shared = new ArrayStore;
    Cache::extend('shared-node-test', fn (): Repository => resolve(CacheManager::class)->repository($shared));
    config(['capell.multi_node' => true, 'cache.default' => 'shared-node-test', 'cache.stores.shared-node-test' => ['driver' => 'shared-node-test']]);
}

function asNode(string $nodeId): void
{
    config(['capell.node_id' => $nodeId]);
}

function nodeEnvironment(string $contents): string
{
    $directory = sys_get_temp_dir() . '/capell-multinode-' . uniqid();
    File::ensureDirectoryExists($directory);
    file_put_contents($directory . '/.env', $contents);
    app()->setBasePath($directory);
    app()->useStoragePath($directory . '/storage');

    return $directory;
}

function debugRemediationRegistry(): SecurityWeaknessRegistry
{
    $registry = new SecurityWeaknessRegistry;
    $registry->registerCheck(new DebugEnabledCheck);
    $registry->registerRemediation(new DebugEnabledRemediation(new EnvironmentRemediation));

    app()->instance(SecurityWeaknessRegistry::class, $registry);

    return $registry;
}

beforeEach(function (): void {
    $this->originalBase = app()->basePath();
    $this->originalStorage = app()->storagePath();
    $this->directories = [];
    FakeConfigClearCommand::$exitCodes = [];
    FakeConfigClearCommand::$calls = [];
    Artisan::registerCommand(new FakeConfigClearCommand);
});

afterEach(function (): void {
    app()->setBasePath($this->originalBase);
    app()->useStoragePath($this->originalStorage);

    collect($this->directories)->each(fn (string $directory): bool => File::deleteDirectory($directory));

    CarbonImmutable::setTestNow();
});

it('keeps single-node remediation unchanged and writes nothing to the node registry', function (): void {
    config(['capell.multi_node' => false]);
    $this->directories[] = nodeEnvironment("APP_DEBUG=true\n");
    $finding = SecurityWeaknessFinding::factory()->create();
    SecurityRemediationApproval::factory()->create();
    $cache = Cache::spy();

    $batch = new ApplyApprovedSecurityRemediationsAction(debugRemediationRegistry())->handle('tester', multiNodeScanId());

    $run = SecurityRemediationRun::query()->sole();
    expect($batch->applied)->toBe(1)
        ->and($run->outcome)->toBe(SecurityRemediationOutcome::Applied)
        ->and($run->expected_nodes)->toBeNull()
        ->and($run->acknowledged_nodes)->toBeNull()
        ->and($finding->refresh()->status)->toBe(SecurityFindingStatus::Remediated);
    $cache->shouldNotHaveReceived('store');
    $cache->shouldNotHaveReceived('put');
    $cache->shouldNotHaveReceived('forever');
});

it('refuses a node-local cache store before changing any setting', function (string $driver): void {
    config(['capell.multi_node' => true, 'cache.default' => 'local-node-test', 'cache.stores.local-node-test.driver' => $driver]);
    $this->directories[] = nodeEnvironment("APP_DEBUG=true\n");
    SecurityWeaknessFinding::factory()->create();
    SecurityRemediationApproval::factory()->create();

    expect(fn (): mixed => new ApplyApprovedSecurityRemediationsAction(debugRemediationRegistry())->handle('tester', multiNodeScanId()))
        ->toThrow(RuntimeException::class, 'Multi-node remediation requires a shared cache store.')
        ->and(file_get_contents(app()->environmentFilePath()))->toBe("APP_DEBUG=true\n")
        ->and(SecurityRemediationRun::query()->count())->toBe(0);
})->with(['array', 'file']);

it('keeps the outcome pending until another node applies its own environment file and acknowledges', function (): void {
    Event::fake([SecurityRemediationApplied::class]);
    useSharedNodeCache();
    $nodes = new SecurityNodeRegistry;
    asNode('node-b');
    $nodes->register();
    asNode('node-a');
    $nodeA = nodeEnvironment("APP_DEBUG=true\n");
    $this->directories[] = $nodeA;
    $finding = SecurityWeaknessFinding::factory()->create();
    SecurityRemediationApproval::factory()->create();
    $registry = debugRemediationRegistry();

    $batch = new ApplyApprovedSecurityRemediationsAction($registry)->handle('tester', multiNodeScanId());

    $run = SecurityRemediationRun::query()->sole();
    expect($batch->applied)->toBe(0)
        ->and($batch->failed)->toBe(0)
        ->and($run->outcome)->toBe(SecurityRemediationOutcome::AppliedPendingNodes)
        ->and($run->message)->toBe(__('capell-core::security.remediation.pending_nodes', ['count' => 1]))
        ->and($run->expected_nodes)->toBe(['node-a', 'node-b'])
        ->and($run->acknowledged_nodes)->toBe(['node-a'])
        ->and($finding->refresh()->status)->toBe(SecurityFindingStatus::Open)
        ->and(file_get_contents($nodeA . '/.env'))->toBe("APP_DEBUG=false\n");
    Event::assertNotDispatched(SecurityRemediationApplied::class);

    // A second approved run must not repeat the change while it is pending elsewhere.
    $second = new ApplyApprovedSecurityRemediationsAction($registry)->handle('tester', multiNodeScanId());
    expect($second->skipped)->toBe([$finding->fingerprint => __('capell-core::security.remediation.pending_nodes_skip')])
        ->and(SecurityRemediationRun::query()->count())->toBe(1);

    $doctor = new SecurityPostureCheck()->check();
    expect($doctor->passed)->toBeFalse()
        ->and($doctor->severity)->toBe(DoctorCheckSeverity::Warning)
        ->and($doctor->message)->toContain(__('capell-core::security.doctor.pending_nodes', ['count' => 1, 'nodes' => 'node-b']))
        ->and($doctor->evidence)->toMatchArray(['pending_remediations' => 1, 'pending_nodes' => ['node-b']]);

    // Node B has its own, still unsafe environment file.
    asNode('node-b');
    $nodeB = nodeEnvironment("APP_DEBUG=true\n");
    $this->directories[] = $nodeB;
    $output = new BufferedOutput;
    $exit = resolve(Kernel::class)->call('capell:security:refresh-node', [], $output);

    expect($exit)->toBe(0)
        ->and($output->fetch())->toContain(__('capell-core::security.command.refresh_node', ['node' => 'node-b', 'acknowledged' => 1, 'completed' => 1]))
        ->and(file_get_contents($nodeB . '/.env'))->toBe("APP_DEBUG=false\n")
        ->and(FakeConfigClearCommand::$calls)->toBe([0])
        ->and($run->refresh()->outcome)->toBe(SecurityRemediationOutcome::Applied)
        ->and($run->acknowledged_nodes)->toBe(['node-a', 'node-b'])
        ->and($finding->refresh()->status)->toBe(SecurityFindingStatus::Remediated)
        ->and(new SecurityPostureCheck()->check()->evidence)->not->toHaveKey('pending_nodes');
    Event::assertDispatchedTimes(SecurityRemediationApplied::class, 1);

    // Running it again is harmless.
    expect(resolve(Kernel::class)->call('capell:security:refresh-node'))->toBe(2)
        ->and(FakeConfigClearCommand::$calls)->toBe([0])
        ->and($run->refresh()->acknowledged_nodes)->toBe(['node-a', 'node-b']);
});

it('does not acknowledge while the configuration cache cannot be cleared', function (): void {
    useSharedNodeCache();
    $nodes = new SecurityNodeRegistry;
    asNode('node-b');
    $nodes->register();
    asNode('node-a');
    $this->directories[] = nodeEnvironment("APP_DEBUG=true\n");
    SecurityWeaknessFinding::factory()->create();
    SecurityRemediationApproval::factory()->create();
    new ApplyApprovedSecurityRemediationsAction(debugRemediationRegistry())->handle('tester', multiNodeScanId());

    asNode('node-b');
    FakeConfigClearCommand::$exitCodes = [1];
    resolve(Kernel::class)->call('capell:security:refresh-node');

    $run = SecurityRemediationRun::query()->sole();
    expect($run->outcome)->toBe(SecurityRemediationOutcome::AppliedPendingNodes)
        ->and($run->acknowledged_nodes)->toBe(['node-a']);
});

it('re-opens the finding naming the nodes that did not refresh once the grace period ends', function (): void {
    useSharedNodeCache();
    config(['capell.security.node_refresh_grace_minutes' => 60, 'capell.security.node_ttl_minutes' => 30]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-03 10:00:00'));
    $nodes = new SecurityNodeRegistry;
    foreach (['node-b', 'node-c'] as $nodeId) {
        asNode($nodeId);
        $nodes->register();
    }

    asNode('node-a');
    $this->directories[] = nodeEnvironment("APP_DEBUG=true\n");
    $finding = SecurityWeaknessFinding::factory()->create();
    SecurityRemediationApproval::factory()->create();
    $registry = debugRemediationRegistry();
    new ApplyApprovedSecurityRemediationsAction($registry)->handle('tester', multiNodeScanId());
    $run = SecurityRemediationRun::query()->sole();

    // Node A's own scan no longer sees the weakness, but must not resolve it yet.
    BuildSecurityPostureInventoryAction::mock()->shouldReceive('handle')->andReturn(new SecurityPostureInventoryData(null, [], ['app.debug' => false], [], CarbonImmutable::now()));
    $analyser = Mockery::mock(SecurityWeaknessAnalyser::class);
    $scan = new RunSecurityWeaknessScanAction($registry, $analyser);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-03 10:20:00'));
    asNode('node-b');
    $nodes->register();
    asNode('node-a');
    expect($scan->handle(false)->resolvedFindings)->toBe(0)
        ->and($finding->refresh()->status)->toBe(SecurityFindingStatus::Open)
        ->and($run->refresh()->outcome)->toBe(SecurityRemediationOutcome::AppliedPendingNodes);

    // Node C left the fleet; node B is alive but never refreshed.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-03 10:45:00'));
    asNode('node-b');
    $nodes->register();
    asNode('node-a');
    $scan->handle(false);
    expect($run->refresh()->outcome)->toBe(SecurityRemediationOutcome::AppliedPendingNodes)
        ->and($run->expected_nodes)->toBe(['node-a', 'node-b', 'node-c']);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-03 11:01:00'));
    asNode('node-b');
    $nodes->register();
    asNode('node-a');
    expect($scan->handle(false)->resolvedFindings)->toBe(0)
        ->and($run->refresh()->outcome)->toBe(SecurityRemediationOutcome::Failed)
        ->and($run->message)->toBe(__('capell-core::security.remediation.nodes_not_refreshed', ['nodes' => 'node-b, node-c']))
        ->and($run->finished_at)->not->toBeNull()
        ->and($finding->refresh()->status)->toBe(SecurityFindingStatus::Open)
        ->and($finding->remediated_at)->toBeNull();

    // Node A's later scans keep it open; the next approved run pushes the change to the fleet again.
    expect($scan->handle(false)->resolvedFindings)->toBe(0)
        ->and($finding->refresh()->status)->toBe(SecurityFindingStatus::Open);
    new ApplyApprovedSecurityRemediationsAction($registry)->handle('tester', multiNodeScanId());
    $retry = SecurityRemediationRun::query()->latest('id')->firstOrFail();
    expect($retry->id)->not->toBe($run->id)
        ->and($retry->outcome)->toBe(SecurityRemediationOutcome::AppliedPendingNodes)
        ->and($retry->expected_nodes)->toBe(['node-a', 'node-b', 'node-c']);

    asNode('node-b');
    resolve(Kernel::class)->call('capell:security:refresh-node');
    expect($retry->refresh()->outcome)->toBe(SecurityRemediationOutcome::AppliedPendingNodes)
        ->and($retry->expected_nodes)->toContain('node-c')
        ->and($finding->refresh()->status)->toBe(SecurityFindingStatus::Open);
    expect(new SecurityPostureCheck()->check()->message)->toContain('node-c');
    $output = new BufferedOutput;
    expect(resolve(Kernel::class)->call('capell:security:refresh-node', ['--retire' => 'node-c'], $output))->toBe(0)
        ->and($output->fetch())->toContain(__('capell-core::security.command.retired', ['node' => 'node-c']))
        ->and($retry->refresh()->outcome)->toBe(SecurityRemediationOutcome::Applied)
        ->and($retry->expected_nodes)->toBe(['node-a', 'node-b'])
        ->and($finding->refresh()->status)->toBe(SecurityFindingStatus::Remediated);
});

it('applies immediately when this node is the only live node', function (): void {
    useSharedNodeCache();
    asNode('node-a');
    $this->directories[] = nodeEnvironment("APP_DEBUG=true\n");
    $finding = SecurityWeaknessFinding::factory()->create();
    SecurityRemediationApproval::factory()->create();

    new ApplyApprovedSecurityRemediationsAction(debugRemediationRegistry())->handle('tester', multiNodeScanId());

    expect(SecurityRemediationRun::query()->sole()->outcome)->toBe(SecurityRemediationOutcome::Applied)
        ->and($finding->refresh()->status)->toBe(SecurityFindingStatus::Remediated)
        ->and(new SecurityNodeRegistry()->activeNodes())->toBe(['node-a']);
});

it('explains that a single-node installation has nothing to refresh', function (): void {
    config(['capell.multi_node' => false]);
    $output = new BufferedOutput;

    expect(resolve(Kernel::class)->call('capell:security:refresh-node', [], $output))->toBe(0)
        ->and($output->fetch())->toContain(__('capell-core::security.command.refresh_single_node'));
});

it('does not acknowledge an ambiguous environment and reports the reason', function (): void {
    useSharedNodeCache();
    $nodes = new SecurityNodeRegistry;
    asNode('node-b');
    $nodes->register();
    asNode('node-a');
    $this->directories[] = nodeEnvironment("APP_DEBUG=true\n");
    $finding = SecurityWeaknessFinding::factory()->create();
    SecurityRemediationApproval::factory()->create();
    new ApplyApprovedSecurityRemediationsAction(debugRemediationRegistry())->handle('tester', multiNodeScanId());

    asNode('node-b');
    $this->directories[] = nodeEnvironment("APP_DEBUG=true\nAPP_DEBUG=true\n");
    $output = new BufferedOutput;
    $exit = resolve(Kernel::class)->call('capell:security:refresh-node', [], $output);
    $run = SecurityRemediationRun::query()->sole();

    expect($exit)->toBe(2)
        ->and($output->fetch())->toContain(__('capell-core::security.remediation.env_ambiguous'))
        ->and($run->acknowledged_nodes)->toBe(['node-a'])
        ->and($run->node_outcomes)->toHaveKey('node-b')
        ->and($run->node_outcomes['node-b'] ?? null)->toBe(__('capell-core::security.remediation.env_ambiguous'))
        ->and($finding->refresh()->status)->toBe(SecurityFindingStatus::Open);
});

it('refuses a node refresh after the approval is revoked', function (): void {
    useSharedNodeCache();
    $nodes = new SecurityNodeRegistry;
    asNode('node-b');
    $nodes->register();
    asNode('node-a');
    $this->directories[] = nodeEnvironment("APP_DEBUG=true\n");
    $finding = SecurityWeaknessFinding::factory()->create();
    $approval = SecurityRemediationApproval::factory()->create();
    new ApplyApprovedSecurityRemediationsAction(debugRemediationRegistry())->handle('tester', multiNodeScanId());
    $approval->update(['revoked_at' => CarbonImmutable::now()]);

    asNode('node-b');
    $nodeB = nodeEnvironment("APP_DEBUG=true\n");
    $this->directories[] = $nodeB;
    $output = new BufferedOutput;
    $exit = resolve(Kernel::class)->call('capell:security:refresh-node', [], $output);
    $run = SecurityRemediationRun::query()->sole();

    expect($exit)->toBe(2)
        ->and($output->fetch())->toContain(__('capell-core::security.remediation.approval_revoked'))
        ->and(file_get_contents($nodeB . '/.env'))->toBe("APP_DEBUG=true\n")
        ->and($run->outcome)->toBe(SecurityRemediationOutcome::Failed)
        ->and($run->acknowledged_nodes)->toBe(['node-a'])
        ->and($finding->refresh()->status)->toBe(SecurityFindingStatus::Open);
});

it('keeps malformed pending membership open and warns in doctor', function (mixed $expected, mixed $acknowledged): void {
    useSharedNodeCache();
    asNode('node-a');
    $finding = SecurityWeaknessFinding::factory()->create();
    new SecurityNodeRegistry()->register();
    $run = SecurityRemediationRun::factory()->create([
        'finding_id' => $finding->id,
        'outcome' => SecurityRemediationOutcome::AppliedPendingNodes,
        'expected_nodes' => $expected,
        'acknowledged_nodes' => $acknowledged,
    ]);

    EvaluatePendingSecurityRemediationsAction::run();

    expect($run->refresh()->outcome)->toBe(SecurityRemediationOutcome::AppliedPendingNodes)
        ->and($finding->refresh()->status)->toBe(SecurityFindingStatus::Open)
        ->and(new SecurityPostureCheck()->check()->message)->toContain(__('capell-core::security.doctor.unknown_fleet', ['run' => $run->id]));
})->with([[null, []], [['node-a'], null], [['node-a', 5], []], [['node-a'], ['node-b']]]);

it('adds a newly registered node to a pending run', function (): void {
    useSharedNodeCache();
    $nodes = new SecurityNodeRegistry;
    asNode('node-b');
    $nodes->register();
    asNode('node-a');
    $this->directories[] = nodeEnvironment("APP_DEBUG=true\n");
    SecurityWeaknessFinding::factory()->create();
    SecurityRemediationApproval::factory()->create();
    new ApplyApprovedSecurityRemediationsAction(debugRemediationRegistry())->handle('tester', multiNodeScanId());
    asNode('node-c');
    $nodes->register();

    EvaluatePendingSecurityRemediationsAction::run();

    expect(SecurityRemediationRun::query()->sole()->expected_nodes)->toBe(['node-a', 'node-b', 'node-c']);
});

it('keeps pending runs open when registry membership is missing or malformed', function (string $damage): void {
    useSharedNodeCache();
    $nodes = new SecurityNodeRegistry;
    asNode('node-b');
    $nodes->register();
    asNode('node-a');
    $this->directories[] = nodeEnvironment("APP_DEBUG=true\n");
    $finding = SecurityWeaknessFinding::factory()->create();
    SecurityRemediationApproval::factory()->create();
    new ApplyApprovedSecurityRemediationsAction(debugRemediationRegistry())->handle('tester', multiNodeScanId());
    $run = SecurityRemediationRun::query()->sole();
    $store = Cache::store();
    match ($damage) {
        'sentinel' => $store->forget('capell:security:nodes:index:created_at'),
        'missing index' => $store->forget('capell:security:nodes'),
        'malformed index' => $store->forever('capell:security:nodes', ['node-a', 42]),
        'missing entry' => $store->forget('capell:security:nodes:node-b'),
        'malformed entry' => $store->forever('capell:security:nodes:node-b', ['node_id' => 'node-b']),
        default => throw new InvalidArgumentException('Unknown registry damage: ' . $damage),
    };

    EvaluatePendingSecurityRemediationsAction::run();

    expect($run->refresh()->outcome)->toBe(SecurityRemediationOutcome::AppliedPendingNodes)
        ->and($finding->refresh()->status)->toBe(SecurityFindingStatus::Open)
        ->and(new SecurityPostureCheck()->check()->message)->toContain(__('capell-core::security.doctor.unknown_fleet', ['run' => $run->id]));
})->with(['sentinel', 'missing index', 'malformed index', 'missing entry', 'malformed entry']);

it('warns about a live node identity collision and cannot settle the run', function (): void {
    useSharedNodeCache();
    $nodes = new SecurityNodeRegistry;
    asNode('node-b');
    $nodes->register();
    asNode('node-a');
    $this->directories[] = nodeEnvironment("APP_DEBUG=true\n");
    $finding = SecurityWeaknessFinding::factory()->create();
    SecurityRemediationApproval::factory()->create();
    new ApplyApprovedSecurityRemediationsAction(debugRemediationRegistry())->handle('tester', multiNodeScanId());
    $entry = Cache::store()->get('capell:security:nodes:node-b');
    $entry['hostname'] = 'another-host';
    Cache::store()->forever('capell:security:nodes:node-b', $entry);
    asNode('node-b');
    $nodes->register();
    EvaluatePendingSecurityRemediationsAction::run();
    $run = SecurityRemediationRun::query()->sole();

    expect(Cache::store()->get('capell:security:nodes:node-b')['collision'])->toBeTrue()
        ->and(new SecurityPostureCheck()->check()->message)->toContain(__('capell-core::security.doctor.node_collision', ['node' => 'node-b']))
        ->and($run->outcome)->toBe(SecurityRemediationOutcome::AppliedPendingNodes)
        ->and($finding->refresh()->status)->toBe(SecurityFindingStatus::Open);
});

it('records a lock timeout without acknowledging the node', function (): void {
    useSharedNodeCache();
    $nodes = new SecurityNodeRegistry;
    asNode('node-b');
    $nodes->register();
    asNode('node-a');
    $this->directories[] = nodeEnvironment("APP_DEBUG=true\n");
    $finding = SecurityWeaknessFinding::factory()->create();
    SecurityRemediationApproval::factory()->create();
    new ApplyApprovedSecurityRemediationsAction(debugRemediationRegistry())->handle('tester', multiNodeScanId());
    asNode('node-b');
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->once()->andThrow(new LockTimeoutException);
    $manager = Mockery::mock(resolve(CacheManager::class))->makePartial();
    Cache::swap($manager);
    $manager->shouldReceive('lock')->once()->with('capell:security:env-remediation', 30)->andReturn($lock);
    $output = new BufferedOutput;
    $exit = resolve(Kernel::class)->call('capell:security:refresh-node', [], $output);
    $run = SecurityRemediationRun::query()->sole();

    expect($exit)->toBe(2)
        ->and($output->fetch())->toContain(__('capell-core::security.remediation.env_locked'))
        ->and($run->acknowledged_nodes)->toBe(['node-a'])
        ->and($run->node_outcomes)->toHaveKey('node-b')
        ->and($run->node_outcomes['node-b'] ?? null)->toBe(__('capell-core::security.remediation.env_locked'))
        ->and($finding->refresh()->status)->toBe(SecurityFindingStatus::Open);
});
