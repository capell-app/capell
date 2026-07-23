<?php

declare(strict_types=1);

use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Enums\PageEnum;
use Capell\Admin\Filament\Pages\UpgradePage;
use Capell\Admin\Jobs\RunCapellUpgradeJob;
use Capell\Core\Actions\Upgrade\CreateUpgradeRunAction;
use Capell\Core\Data\Upgrade\UpgradeReadinessReportData;
use Capell\Core\Data\UpgradeRunOptions;
use Capell\Core\Enums\Upgrade\UpgradeReadinessResult;
use Capell\Core\Enums\Upgrade\UpgradeRunStatus;
use Capell\Core\Models\UpgradeRun;
use Capell\Core\Support\Upgrade\DatabaseUpgradeLock;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

it('guards queued upgrade runs by run id and uses bounded retry backoff', function (): void {
    $job = new RunCapellUpgradeJob(42);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('42')
        ->and($job->tries)->toBe(10)
        ->and($job->backoff())->toBe([60, 120, 300]);
});

it('is registered in the admin page enum', function (): void {
    expect(array_map(fn (PageEnum $page): string => $page->value, PageEnum::cases()))
        ->toContain(UpgradePage::class);
});

it('resolves advisory versions through the documented fallback order', function (): void {
    $page = new UpgradePage;
    $notice = [
        'installed_version' => '',
        'current_version' => '1.2.0',
        'recommended_version' => '',
        'latest_version' => '1.4.0',
        'fixed_versions' => ['capell-app/capell' => '1.3.0'],
        'affected_packages' => [
            'invalid',
            ['installed_version' => null, 'fixed_version' => []],
            ['installed_version' => '1.1.0', 'fixed_version' => '1.5.0'],
        ],
    ];

    expect($page->noticeInstalledVersion($notice))->toBe('1.1.0')
        ->and($page->noticeRecommendedVersion($notice))->toBe('1.5.0');

    unset($notice['installed_version'], $notice['recommended_version']);

    expect($page->noticeInstalledVersion($notice))->toBe('1.2.0')
        ->and($page->noticeRecommendedVersion($notice))->toBe('1.4.0');

    unset($notice['current_version'], $notice['latest_version']);
    $notice['affected_packages'] = ['invalid'];

    expect($page->noticeInstalledVersion($notice))->toBe(__('capell-admin::generic.unknown'))
        ->and($page->noticeRecommendedVersion($notice))->toBe('capell-app/capell: 1.3.0');
});

it('can be reached from the admin with view permission', function (): void {
    Permission::create(['name' => 'View:UpgradePage', 'guard_name' => 'web']);
    Permission::create(['name' => CapellPermission::RunUpgrades->name(), 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:UpgradePage', CapellPermission::RunUpgrades->name());

    expect(UpgradePage::getNavigationLabel())->toBe(__('capell-admin::navigation.upgrade'));

    Livewire::test(UpgradePage::class)
        ->assertSuccessful()
        ->assertSee(__('capell-admin::heading.upgrade'))
        ->assertSee(__('capell-admin::generic.update_center_status'))
        ->assertSee(__('capell-admin::generic.updates_to_review'))
        ->assertSee(__('capell-admin::generic.no_update_advisories_first_check_description'));
});

it('queues a dry-run upgrade from the page action', function (): void {
    Queue::fake();
    config(['queue.default' => 'database']);
    ensureJobsTableExistsForUpgradeTests();

    Permission::create(['name' => 'View:UpgradePage', 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:UpgradePage');

    Livewire::test(UpgradePage::class)
        ->callAction('dryRunUpgrade')
        ->assertSet('lastExitCode', null)
        ->assertSet('lastOutput', __('capell-admin::message.upgrade_queued'))
        ->assertSee('php artisan capell:upgrade --force --no-clear-cache --dry-run');

    $run = UpgradeRun::query()->where('status', UpgradeRunStatus::Queued)->where('dry_run', true)->first();

    Queue::assertPushed(
        RunCapellUpgradeJob::class,
        fn (RunCapellUpgradeJob $job): bool => $run instanceof UpgradeRun && $job->upgradeRunId() === (int) $run->getKey(),
    );
    expect($run)->toBeInstanceOf(UpgradeRun::class);
});

it('shows the manual upgrade command instead of running inline when queue is sync', function (): void {
    config(['queue.default' => 'sync']);

    Permission::create(['name' => 'View:UpgradePage', 'guard_name' => 'web']);
    Permission::create(['name' => CapellPermission::RunUpgrades->name(), 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:UpgradePage', CapellPermission::RunUpgrades->name());

    Livewire::test(UpgradePage::class)
        ->callAction('runUpgrade')
        ->assertSet('lastExitCode', null)
        ->assertSet('lastOutput', fn (string $output): bool => str_contains($output, (string) __('capell-admin::message.upgrade_manual_required', [
            'command' => 'php artisan capell:upgrade --force --no-clear-cache',
        ])) && str_contains($output, 'Queue driver is sync'))
        ->assertSee('php artisan capell:upgrade --force --no-clear-cache');

    expect(UpgradeRun::query()->where('status', UpgradeRunStatus::ManualRequired)->exists())->toBeTrue();
});

it('requires execute permission before queueing upgrades', function (): void {
    Queue::fake();
    config(['queue.default' => 'database']);
    ensureJobsTableExistsForUpgradeTests();

    Permission::create(['name' => 'View:UpgradePage', 'guard_name' => 'web']);
    Permission::create(['name' => CapellPermission::RunUpgrades->name(), 'guard_name' => 'web']);
    test()->actingAsUser();
    test()->authenticatedUser()->givePermissionTo('View:UpgradePage');

    Livewire::test(UpgradePage::class)
        ->assertActionHidden('dryRunUpgrade');

    Queue::assertNothingPushed();
    expect(UpgradeRun::query()->count())->toBe(0);
});

it('runs the core upgrade action from the queued job', function (): void {
    $run = queuedUpgradeRun(dryRun: true);

    new RunCapellUpgradeJob((int) $run->getKey())->handle();

    expect($run->refresh()->status)->toBe(UpgradeRunStatus::Succeeded)
        // A finished upgrade must leave the lock free for the next one.
        ->and(new DatabaseUpgradeLock()->isHeld('capell:upgrade'))->toBeFalse();
});

it('requeues the queued job when the core upgrade action cannot acquire the upgrade lock', function (): void {
    $run = queuedUpgradeRun();

    $lock = new DatabaseUpgradeLock;
    $token = (string) $lock->acquire('capell:upgrade', 60);

    try {
        new RunCapellUpgradeJob((int) $run->getKey())->handle();
    } finally {
        $lock->release('capell:upgrade', $token);
    }

    expect($run->refresh()->status)->toBe(UpgradeRunStatus::Queued)
        ->and($run->events()->where('message', 'like', '%queued job will retry%')->exists())->toBeTrue();
});

it('records that queued upgrades leave cache clearing to deploys', function (): void {
    $run = queuedUpgradeRun(dryRun: true);

    new RunCapellUpgradeJob((int) $run->getKey())->handle();

    expect($run->events()->where('message', 'like', 'Cache clearing skipped for queued upgrade%')->exists())->toBeTrue();
});

it('ignores missing and already claimed queued job runs', function (): void {
    $running = queuedUpgradeRun();
    $running->forceFill(['status' => UpgradeRunStatus::Running])->save();

    new RunCapellUpgradeJob(999999)->handle();
    new RunCapellUpgradeJob((int) $running->getKey())->handle();

    expect($running->refresh()->status)->toBe(UpgradeRunStatus::Running);
});

it('marks active runs failed when the queued worker fails outside handle', function (): void {
    $queued = queuedUpgradeRun();
    $running = queuedUpgradeRun();
    $running->forceFill(['status' => UpgradeRunStatus::Running])->save();
    $succeeded = queuedUpgradeRun();
    $succeeded->forceFill(['status' => UpgradeRunStatus::Succeeded])->save();

    new RunCapellUpgradeJob((int) $queued->getKey())->failed(new RuntimeException('worker crashed with token=secret-value'));
    new RunCapellUpgradeJob((int) $running->getKey())->failed(new RuntimeException('running worker crashed'));
    new RunCapellUpgradeJob((int) $succeeded->getKey())->failed(new RuntimeException('should not overwrite'));

    expect($queued->refresh()->status)->toBe(UpgradeRunStatus::Failed)
        ->and($queued->failure_reason)->toBe('worker crashed with token= [redacted]')
        ->and($queued->events()->where('level', 'error')->exists())->toBeTrue()
        ->and($running->refresh()->status)->toBe(UpgradeRunStatus::Failed)
        ->and($succeeded->refresh()->status)->toBe(UpgradeRunStatus::Succeeded);
});

it('returns the existing active run instead of dispatching duplicate upgrade jobs', function (): void {
    Queue::fake();
    config(['queue.default' => 'database']);
    ensureJobsTableExistsForUpgradeTests();

    $activeRun = queuedUpgradeRun(dryRun: true);

    Permission::create(['name' => 'View:UpgradePage', 'guard_name' => 'web']);
    Permission::create(['name' => CapellPermission::RunUpgrades->name(), 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:UpgradePage', CapellPermission::RunUpgrades->name());

    Livewire::test(UpgradePage::class)
        ->callAction('runUpgrade')
        ->assertSet('lastOutput', __('capell-admin::message.upgrade_queued'));

    Queue::assertNothingPushed();
    expect(UpgradeRun::query()->count())->toBe(1)
        ->and(UpgradeRun::query()->first()?->is($activeRun))->toBeTrue();
});

function queuedUpgradeRun(bool $dryRun = false): UpgradeRun
{
    return CreateUpgradeRunAction::run(
        options: new UpgradeRunOptions(dryRun: $dryRun, force: true, noClearCache: true),
        readiness: new UpgradeReadinessReportData(UpgradeReadinessResult::Ready, []),
        status: UpgradeRunStatus::Queued,
        manualCommands: [],
    );
}

function ensureJobsTableExistsForUpgradeTests(): void
{
    Schema::dropIfExists('jobs');
    Schema::create('jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
}
