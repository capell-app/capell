<?php

declare(strict_types=1);

use Capell\Admin\Data\Extensions\ExtensionRemovalRequestData;
use Capell\Core\Actions\Extensions\VerifyInstalledExtensionAction;
use Capell\Core\Actions\RemovePackageAction;
use Capell\Core\Actions\UninstallPackageAction;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Composer\ComposerStateSnapshot;
use Capell\Marketplace\Actions\RestoreComposerStateAction;
use Capell\Marketplace\Actions\RunPostOperationHealthCheckAction;
use Capell\Marketplace\Contracts\MarketplaceComposerChangePublisher;
use Capell\Marketplace\Contracts\MarketplaceComposerRemovalAuthorizer;
use Capell\Marketplace\Contracts\MarketplaceComposerRunner;
use Capell\Marketplace\Data\MarketplaceHealthCheckResultData;
use Capell\Marketplace\Data\MarketplaceUninstallOptionsData;
use Capell\Marketplace\Enums\MarketplaceHealthProbeOutcome;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallFailureType;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceOperationType;
use Capell\Marketplace\Jobs\RunMarketplaceUninstallAttemptJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Support\MarketplaceExtensionRemovalCoordinator;
use Capell\Marketplace\Support\MarketplaceOperationVocabulary;
use Capell\Marketplace\Tests\Support\JobUninstallDataDeleter;
use Capell\Marketplace\Tests\Support\RecordingMarketplaceComposerRunner;
use Capell\Marketplace\Tests\Support\UninstallRecordingAuthorizer;
use Capell\Marketplace\Tests\Support\UninstallRecordingPublisher;
use Capell\Marketplace\Tests\Support\UninstallRecordingRemover;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(CreatesAdminUser::class);

const UNINSTALL_PACKAGE_NAME = 'capell-app/marketplace-uninstall-package';

function registerRemovablePackage(): void
{
    CapellCore::registerPackage(UNINSTALL_PACKAGE_NAME, PackageTypeEnum::Plugin, version: '1.0.0');
    CapellCore::markPackageInstalled(UNINSTALL_PACKAGE_NAME);
}

function uninstallAttempt(bool $deletePackage = false, bool $deleteData = false, array $overrides = [], array $packageNames = []): MarketplaceInstallAttempt
{
    return MarketplaceInstallAttempt::query()->create([
        'composer_name' => UNINSTALL_PACKAGE_NAME,
        'extension_slug' => 'marketplace-uninstall-package',
        'extension_name' => 'Marketplace Uninstall Package',
        'kind' => 'plugin',
        'status' => MarketplaceInstallIntentStatus::Queued,
        'operation' => MarketplaceOperationType::Uninstall,
        'uninstall_options' => new MarketplaceUninstallOptionsData(
            deletePackage: $deletePackage,
            deleteData: $deleteData,
            packageNames: $packageNames,
        )->toArray(),
        'composer_command' => 'composer remove ' . UNINSTALL_PACKAGE_NAME,
        'queued_at' => now(),
        ...$overrides,
    ]);
}

function composerOnlyUninstallAttempt(array $packageNames, bool $deleteData = false): MarketplaceInstallAttempt
{
    return uninstallAttempt(
        deletePackage: true,
        overrides: [
            'uninstall_options' => new MarketplaceUninstallOptionsData(
                deletePackage: true,
                deleteData: $deleteData,
                packageNames: $packageNames,
                runLifecycle: false,
            )->toArray(),
        ],
    );
}

function runUninstallJob(MarketplaceInstallAttempt $attempt): void
{
    new RunMarketplaceUninstallAttemptJob((int) $attempt->getKey())->handle(
        resolve(MarketplaceComposerRunner::class),
    );
}

/**
 * A composer runner is still injected because the base job signature demands
 * one. The uninstall must never touch it — the removal is RemovePackageAction —
 * and every test here proves that by asserting the runner recorded nothing.
 */
function idleComposerRunner(): RecordingMarketplaceComposerRunner
{
    $runner = new RecordingMarketplaceComposerRunner('unused');

    app()->instance(MarketplaceComposerRunner::class, $runner);

    return $runner;
}

/** @param list<string> $recordedRemovals */
function fakeSuccessfulPackageRemoval(array &$recordedRemovals): void
{
    RemovePackageAction::mock()
        ->shouldReceive('handle')
        ->andReturnUsing(function (string $name, ?callable $finalize = null, bool $requiresServerSideTooling = false, ?int $timeoutSeconds = null) use (&$recordedRemovals): array {
            unset($finalize, $timeoutSeconds);
            $recordedRemovals[] = $name . '|' . ($requiresServerSideTooling ? 'gated' : 'ungated');

            return [
                'package' => $name,
                'status' => 'removed',
                'message' => 'removed',
                'output' => 'Package removed',
                'cache_cleared' => true,
            ];
        });
}

function failPackageRemoval(string $reason): void
{
    RemovePackageAction::mock()
        ->shouldReceive('handle')
        ->andThrow(new RuntimeException($reason));
}

function failUninstallHealthCheck(string $reason): void
{
    app()->instance(RunPostOperationHealthCheckAction::class, new readonly class($reason)
    {
        public function __construct(private string $reason) {}

        public function handle(int $budgetSeconds): MarketplaceHealthCheckResultData
        {
            unset($budgetSeconds);

            return new MarketplaceHealthCheckResultData(
                bootProbe: MarketplaceHealthProbeOutcome::Failed,
                httpProbe: MarketplaceHealthProbeOutcome::Skipped,
                failureReason: $this->reason,
            );
        }
    });
}

/**
 * Keep the real recovery `composer install` out of the suite. Bound as an
 * instance rather than mocked because RestoreComposerStateAction is final.
 */
function fakeUninstallComposerRestore(): void
{
    app()->instance(RestoreComposerStateAction::class, new class
    {
        public function handle(ComposerStateSnapshot $snapshot, int $timeoutSeconds = 300): bool
        {
            unset($snapshot, $timeoutSeconds);

            return true;
        }
    });
}

function uninstallTimelineHas(MarketplaceInstallAttempt $attempt, string $key): bool
{
    return $attempt->events()
        ->where('message', MarketplaceOperationVocabulary::translate(MarketplaceOperationType::Uninstall, $key))
        ->exists();
}

it('tears the extension down and keeps the package files when delete package was not chosen', function (): void {
    Notification::fake();
    test()->createUserWithRole('super_admin');
    registerRemovablePackage();
    $runner = idleComposerRunner();
    $removals = [];
    fakeSuccessfulPackageRemoval($removals);
    $attempt = uninstallAttempt();

    runUninstallJob($attempt);
    $attempt->refresh();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Succeeded)
        ->and(CapellCore::isPackageInstalled(UNINSTALL_PACKAGE_NAME))->toBeFalse()
        // The package stays registered: only its installation was undone.
        ->and(CapellCore::hasPackage(UNINSTALL_PACKAGE_NAME))->toBeTrue()
        ->and($removals)->toBe([])
        ->and($runner->ran())->toBeFalse()
        ->and(uninstallTimelineHas($attempt, 'timeline_lifecycle_completed'))->toBeTrue()
        ->and(uninstallTimelineHas($attempt, 'timeline_composer_skipped_package_retained'))->toBeTrue();
});

it('removes the package with Composer when delete package was chosen', function (): void {
    Notification::fake();
    test()->createUserWithRole('super_admin');
    registerRemovablePackage();
    idleComposerRunner();
    $removals = [];
    fakeSuccessfulPackageRemoval($removals);
    $attempt = uninstallAttempt(deletePackage: true);

    runUninstallJob($attempt);
    $attempt->refresh();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Succeeded)
        ->and($removals)->toBe([UNINSTALL_PACKAGE_NAME . '|gated'])
        ->and(uninstallTimelineHas($attempt, 'timeline_package_removed'))->toBeTrue()
        ->and(uninstallTimelineHas($attempt, 'timeline_composer_skipped_package_retained'))->toBeFalse();
});

it('removes confirmed dependents before the requested package', function (): void {
    Notification::fake();
    test()->createUserWithRole('super_admin');
    registerRemovablePackage();
    $dependent = 'capell-app/marketplace-uninstall-dependent';
    CapellCore::registerPackage($dependent, PackageTypeEnum::Plugin, version: '1.0.0');
    CapellCore::getPackage($dependent)->requirements = [UNINSTALL_PACKAGE_NAME];
    CapellCore::markPackageInstalled($dependent);
    idleComposerRunner();
    $removals = [];
    fakeSuccessfulPackageRemoval($removals);
    $attempt = uninstallAttempt(
        deletePackage: true,
        packageNames: [$dependent, UNINSTALL_PACKAGE_NAME],
    );

    runUninstallJob($attempt);
    $attempt->refresh();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Succeeded)
        ->and(CapellCore::isPackageInstalled($dependent))->toBeFalse()
        ->and(CapellCore::isPackageInstalled(UNINSTALL_PACKAGE_NAME))->toBeFalse()
        ->and($removals)->toBe([
            $dependent . '|gated',
            UNINSTALL_PACKAGE_NAME . '|gated',
        ]);
});

it('deletes retained package files after the extension lifecycle was already uninstalled', function (): void {
    Notification::fake();
    test()->createUserWithRole('super_admin');
    CapellCore::registerPackage(UNINSTALL_PACKAGE_NAME, PackageTypeEnum::Plugin, version: '1.0.0');
    idleComposerRunner();
    $removals = [];
    fakeSuccessfulPackageRemoval($removals);
    UninstallPackageAction::mock()->shouldNotReceive('handle');
    $attempt = composerOnlyUninstallAttempt([UNINSTALL_PACKAGE_NAME]);

    runUninstallJob($attempt);
    $attempt->refresh();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Succeeded)
        ->and($removals)->toBe([UNINSTALL_PACKAGE_NAME . '|gated'])
        ->and(uninstallTimelineHas($attempt, 'timeline_lifecycle_started'))->toBeFalse();
});

it('deletes extension-owned data when the lifecycle was already uninstalled', function (): void {
    Notification::fake();
    JobUninstallDataDeleter::$deletedPackages = [];
    CapellCore::registerPackage(UNINSTALL_PACKAGE_NAME, PackageTypeEnum::Plugin, JobUninstallDataDeleter::class, version: '1.0.0');
    idleComposerRunner();
    $removals = [];
    fakeSuccessfulPackageRemoval($removals);

    $attempt = composerOnlyUninstallAttempt([UNINSTALL_PACKAGE_NAME], deleteData: true);

    runUninstallJob($attempt);

    expect($attempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::Succeeded)
        ->and(JobUninstallDataDeleter::$deletedPackages)->toBe([UNINSTALL_PACKAGE_NAME])
        ->and($removals)->toBe([UNINSTALL_PACKAGE_NAME . '|gated']);
});

it('gates the queued Composer removal on server-side tooling exactly as the in-request path did', function (): void {
    Notification::fake();
    test()->createUserWithRole('super_admin');
    registerRemovablePackage();
    idleComposerRunner();
    $removals = [];
    fakeSuccessfulPackageRemoval($removals);

    runUninstallJob(uninstallAttempt(deletePackage: true));

    expect($removals)->toBe([UNINSTALL_PACKAGE_NAME . '|gated']);
});

it('deletes extension-owned data only when the operator asked for it', function (bool $deleteData): void {
    Notification::fake();
    test()->createUserWithRole('super_admin');
    registerRemovablePackage();
    idleComposerRunner();
    $removals = [];
    fakeSuccessfulPackageRemoval($removals);

    $observed = [];
    UninstallPackageAction::mock()
        ->shouldReceive('handle')
        ->andReturnUsing(function ($package, bool $delete = false, bool $deleteDataArgument = false, bool $requiresServerSideTooling = false) use (&$observed): void {
            unset($requiresServerSideTooling);
            $observed[] = ['delete' => $delete, 'delete_data' => $deleteDataArgument];
            CapellCore::markPackageUninstalled($package->name);
        });

    runUninstallJob(uninstallAttempt(deleteData: $deleteData));

    // delete is always false: the Composer removal is a stage of this job, not
    // something the core action runs inline outside the snapshot and budget.
    expect($observed)->toBe([['delete' => false, 'delete_data' => $deleteData]]);
})->with([
    'keeps the data' => [false],
    'deletes the data' => [true],
]);

it('fails at the lifecycle stage without ever reaching Composer when the teardown throws', function (): void {
    Notification::fake();
    registerRemovablePackage();
    idleComposerRunner();
    fakeUninstallComposerRestore();
    $removals = [];
    fakeSuccessfulPackageRemoval($removals);

    UninstallPackageAction::mock()
        ->shouldReceive('handle')
        ->andThrow(new RuntimeException('The extension refused to tear itself down.'));

    $attempt = uninstallAttempt(deletePackage: true);
    runUninstallJob($attempt);
    $attempt->refresh();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($attempt->failure_stage)->toBe(MarketplaceInstallFailureStage::Lifecycle->value)
        ->and($attempt->failure_reason)->toBe('The extension refused to tear itself down.')
        ->and($removals)->toBe([]);
});

it('tells the operator the teardown was not undone when the removal fails after it ran', function (): void {
    Notification::fake();
    registerRemovablePackage();
    idleComposerRunner();
    fakeUninstallComposerRestore();
    failPackageRemoval('Composer could not remove the package.');

    $attempt = uninstallAttempt(deletePackage: true);
    runUninstallJob($attempt);
    $attempt->refresh();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($attempt->failure_stage)->toBe(MarketplaceInstallFailureStage::Composer->value)
        // The Composer stage threw rather than exiting non-zero, so the state is
        // restored and the failure is reported with the thrown reason. The
        // rollback claim must not read as "nothing happened".
        ->and($attempt->failure_reason)->toContain('Composer could not remove the package.');
});

it('rolls back and names the teardown as retained when the health check fails', function (): void {
    Notification::fake();
    registerRemovablePackage();
    idleComposerRunner();
    fakeUninstallComposerRestore();
    $removals = [];
    fakeSuccessfulPackageRemoval($removals);
    failUninstallHealthCheck('The site did not boot after the uninstall.');

    $attempt = uninstallAttempt(deletePackage: true, deleteData: true);
    runUninstallJob($attempt);
    $attempt->refresh();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($attempt->failure_stage)->toBe(MarketplaceInstallFailureStage::HealthCheck->value)
        // The teardown outranks the health check: it is the part that is not
        // coming back with the code.
        ->and($attempt->failure_type)->toBe(MarketplaceInstallFailureType::LifecycleException->value)
        ->and($attempt->failure_reason)->toContain('was NOT undone')
        ->and($attempt->failure_reason)->toContain('The site did not boot after the uninstall.')
        ->and(uninstallTimelineHas($attempt, 'timeline_rollback_lifecycle_retained'))->toBeTrue();
});

it('stops before Composer when a cancel is taken while the teardown is running', function (): void {
    Notification::fake();
    registerRemovablePackage();
    idleComposerRunner();
    $removals = [];
    fakeSuccessfulPackageRemoval($removals);

    $attempt = uninstallAttempt(deletePackage: true);

    UninstallPackageAction::mock()
        ->shouldReceive('handle')
        ->andReturnUsing(function ($package) use ($attempt): void {
            CapellCore::markPackageUninstalled($package->name);
            // The operator cancels while the extension is tearing itself down.
            $attempt->newQuery()->whereKey($attempt->getKey())->update([
                'status' => MarketplaceInstallIntentStatus::CancelRequested->value,
                'cancel_requested_at' => now(),
            ]);
        });

    runUninstallJob($attempt);
    $attempt->refresh();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Cancelled)
        ->and($attempt->failure_type)->toBe(MarketplaceInstallFailureType::CancelledAfterLifecycle->value)
        ->and($attempt->failure_stage)->toBe(MarketplaceInstallFailureStage::Lifecycle->value)
        // The whole point: the package files are untouched, because Composer
        // never ran.
        ->and($removals)->toBe([]);
});

it('refuses to uninstall a package Capell does not know about', function (): void {
    Notification::fake();
    idleComposerRunner();
    fakeUninstallComposerRestore();

    $attempt = uninstallAttempt();
    runUninstallJob($attempt);
    $attempt->refresh();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($attempt->failure_stage)->toBe(MarketplaceInstallFailureStage::Lifecycle->value)
        ->and($attempt->failure_reason)->toContain('is not known to Capell');
});

it('reports uninstall progress against its own stage sequence', function (): void {
    Notification::fake();
    test()->createUserWithRole('super_admin');
    registerRemovablePackage();
    idleComposerRunner();
    $removals = [];
    fakeSuccessfulPackageRemoval($removals);

    $attempt = uninstallAttempt(deletePackage: true);
    runUninstallJob($attempt);
    $attempt->refresh();

    expect($attempt->progress_total)->toBe(5)
        ->and($attempt->progress_current)->toBe(5);
});

it('does not verify a package during uninstall', function (): void {
    VerifyInstalledExtensionAction::shouldRun()->never();
    $attempt = uninstallAttempt();
    registerRemovablePackage();
    idleComposerRunner();
    runUninstallJob($attempt);
    expect($attempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::Succeeded);
});

function tagUninstallPublisher(MarketplaceComposerChangePublisher $publisher): void
{
    app()->instance('test.marketplace.uninstall-publisher', $publisher);
    app()->tag(['test.marketplace.uninstall-publisher'], MarketplaceComposerChangePublisher::TAG);
}

it('keeps a logged-out worker from calling a legacy remover without deployment approval', function (): void {
    Notification::fake();
    registerRemovablePackage();
    idleComposerRunner();
    $removals = [];
    fakeSuccessfulPackageRemoval($removals);
    $publisher = new UninstallRecordingRemover;
    tagUninstallPublisher($publisher);

    $attempt = uninstallAttempt(deletePackage: true);
    $job = new ReflectionClass(RunMarketplaceUninstallAttemptJob::class)->newInstanceWithoutConstructor();
    $job->__unserialize(["\0*\0installAttemptId" => (int) $attempt->getKey()]);
    $job->handle(resolve(MarketplaceComposerRunner::class));

    $attempt->refresh();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Succeeded)
        ->and($publisher->removed)->toBe([])
        ->and($publisher->published)->toBe([])
        ->and(data_get($attempt->deployment, 'status'))->toBe('unavailable')
        ->and(uninstallTimelineHas($attempt, 'timeline_requirement_removal_manual'))->toBeTrue();
});

it('tells the person to remove the requirement by hand when the publisher can only add one', function (): void {
    Notification::fake();
    registerRemovablePackage();
    idleComposerRunner();
    $removals = [];
    fakeSuccessfulPackageRemoval($removals);
    $publisher = new UninstallRecordingPublisher;
    tagUninstallPublisher($publisher);

    $attempt = uninstallAttempt(deletePackage: true);
    runUninstallJob($attempt);
    $attempt->refresh();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Succeeded)
        ->and($publisher->published)->toBe([])
        // An unavailable handoff is what lists the attempt as needing attention.
        ->and(data_get($attempt->deployment, 'status'))->toBe('unavailable')
        ->and(data_get($attempt->deployment, 'failure_reason'))->toContain(UNINSTALL_PACKAGE_NAME)
        ->and(data_get($attempt->deployment, 'failure_reason'))->toContain('composer.json in the deployment repository')
        ->and(uninstallTimelineHas($attempt, 'timeline_requirement_removal_manual'))->toBeTrue();
});

it('leaves the deployment source alone when the uninstall kept the package or rolled back', function (bool $deletePackage, bool $failHealth): void {
    Notification::fake();
    registerRemovablePackage();
    idleComposerRunner();
    fakeUninstallComposerRestore();
    $removals = [];
    fakeSuccessfulPackageRemoval($removals);

    if ($failHealth) {
        failUninstallHealthCheck('The site did not boot after the uninstall.');
    }

    $publisher = new UninstallRecordingRemover;
    tagUninstallPublisher($publisher);

    $attempt = uninstallAttempt(deletePackage: $deletePackage);
    runUninstallJob($attempt);
    $attempt->refresh();

    expect($publisher->removed)->toBe([])
        ->and($publisher->published)->toBe([])
        ->and($attempt->deployment)->toBeNull();
})->with([
    'package files kept' => [false, false],
    'health check rolled back' => [true, true],
]);

function tagUninstallAuthorizer(MarketplaceComposerRemovalAuthorizer $authorizer, string $containerKey = 'test-removal-authorizer'): void
{
    app()->instance($containerKey, $authorizer);
    app()->tag([$containerKey], MarketplaceComposerRemovalAuthorizer::TAG);
}

/** @return array{MarketplaceInstallAttempt, RunMarketplaceUninstallAttemptJob} */
function queueRealUninstall(): array
{
    $queue = Queue::fake()->serializeAndRestore();
    $outcome = new MarketplaceExtensionRemovalCoordinator()->queue(new ExtensionRemovalRequestData(
        composerName: UNINSTALL_PACKAGE_NAME,
        packageNames: [UNINSTALL_PACKAGE_NAME],
        deletePackage: true,
        deleteData: false,
        extensionSlug: 'marketplace-uninstall-package',
        extensionName: 'Marketplace Uninstall Package',
        kind: 'plugin',
    ));

    expect($outcome->accepted)->toBeTrue();
    $attempt = MarketplaceInstallAttempt::query()->latest('id')->firstOrFail();
    $job = $queue->pushed(RunMarketplaceUninstallAttemptJob::class)->sole();
    throw_unless($job instanceof RunMarketplaceUninstallAttemptJob, RuntimeException::class);
    auth()->logout();

    return [$attempt, $job];
}

it('carries the signed-in operator approval through the queued uninstall to a logged-out worker', function (): void {
    Notification::fake();
    registerRemovablePackage();
    $runner = idleComposerRunner();
    $removals = [];
    fakeSuccessfulPackageRemoval($removals);
    $authorizer = new UninstallRecordingAuthorizer;
    tagUninstallAuthorizer($authorizer);
    $this->actingAsAdmin();
    $actor = $this->authenticatedUser();

    [$attempt, $job] = queueRealUninstall();

    expect($authorizer->issued['actor'] ?? null)->toBe($actor)
        ->and($authorizer->issued['request']->operationId ?? null)->toBe((string) $attempt->getKey())
        ->and($authorizer->issued['request']->composerNames ?? null)->toBe([UNINSTALL_PACKAGE_NAME])
        ->and($authorizer->removed)->toBeNull()
        ->and(auth()->user())->toBeNull()
        ->and(serialize($job))->not->toContain('opaque-authorisation-lookup');

    $job->handle($runner);

    $attempt->refresh();
    expect($authorizer->removed?->approvalId)->toBe('opaque-authorisation-lookup')
        ->and($removals)->not->toBe([])
        ->and($attempt->status)->toBe(MarketplaceInstallIntentStatus::Succeeded)
        ->and(data_get($attempt->deployment, 'reference'))->toBe('https://example.test/pull/2')
        ->and(uninstallTimelineHas($attempt, 'timeline_requirement_removal_published'))->toBeTrue()
        ->and($attempt->toJson())->not->toContain('opaque-authorisation-lookup');
});

it('keeps the repository handoff manual when an actor or unique target cannot approve it', function (string $reason): void {
    Notification::fake();
    registerRemovablePackage();
    $runner = idleComposerRunner();
    $removals = [];
    fakeSuccessfulPackageRemoval($removals);
    $authorizer = new UninstallRecordingAuthorizer;
    tagUninstallAuthorizer($authorizer);

    if ($reason !== 'no actor') {
        $this->actingAsAdmin();
    }

    if ($reason === 'ambiguous providers') {
        tagUninstallAuthorizer(new UninstallRecordingAuthorizer, 'other-removal-authorizer');
    }

    $authorizer->canAuthorize = $reason !== 'ambiguous repository';
    [$attempt, $job] = queueRealUninstall();
    $job->handle($runner);

    expect($authorizer->removed)->toBeNull()
        ->and($attempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::Succeeded)
        ->and(data_get($attempt->deployment, 'fallback'))->toBe('manual_requirement_removal')
        ->and($authorizer->issued !== null)->toBe($reason === 'ambiguous repository');
})->with(['no actor', 'ambiguous repository', 'ambiguous providers']);

it('does not forward an approval to an issuer or uninstall whose persisted approval facts changed', function (string $mismatch): void {
    Notification::fake();
    registerRemovablePackage();
    $runner = idleComposerRunner();
    $removals = [];
    fakeSuccessfulPackageRemoval($removals);
    $authorizer = new UninstallRecordingAuthorizer;
    tagUninstallAuthorizer($authorizer);
    $this->actingAsAdmin();

    [$attempt, $job] = queueRealUninstall();
    $replacement = new class extends UninstallRecordingAuthorizer {};
    if ($mismatch === 'issuer') {
        app()->instance('test-removal-authorizer', $replacement);
    } elseif ($mismatch === 'actor') {
        $attempt->update(['user_id' => $this->createUser()->getKey()]);
    } elseif ($mismatch === 'names') {
        $another = 'capell-app/another-marketplace-package';
        CapellCore::registerPackage($another, PackageTypeEnum::Plugin, version: '1.0.0');
        CapellCore::markPackageInstalled($another);
        $attempt->update(['uninstall_options' => new MarketplaceUninstallOptionsData(
            deletePackage: true,
            packageNames: [$another, UNINSTALL_PACKAGE_NAME],
        )->toArray()]);
    } else {
        $payload = $job->__serialize();
        $attempt = uninstallAttempt(
            deletePackage: true,
            overrides: ['user_id' => $attempt->user_id],
        );
        $payload["\0*\0installAttemptId"] = (int) $attempt->getKey();
        $job = new ReflectionClass(RunMarketplaceUninstallAttemptJob::class)->newInstanceWithoutConstructor();
        $job->__unserialize($payload);
    }

    $job->handle($runner);

    expect($replacement->removed)->toBeNull()
        ->and($authorizer->removed)->toBeNull()
        ->and($attempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::Succeeded)
        ->and(data_get($attempt->deployment, 'fallback'))->toBe('manual_requirement_removal');
})->with(['issuer', 'actor', 'names', 'attempt']);

it('keeps successful local uninstall and a manual warning when provider consumption rejects approval', function (): void {
    Notification::fake();
    $logger = Log::spy();
    registerRemovablePackage();
    $runner = idleComposerRunner();
    $removals = [];
    fakeSuccessfulPackageRemoval($removals);
    $authorizer = new UninstallRecordingAuthorizer;
    tagUninstallAuthorizer($authorizer);
    $this->actingAsAdmin();

    [$attempt, $job] = queueRealUninstall();
    $authorizer->canConsume = false;
    $job->handle($runner);

    expect($authorizer->removed)->not->toBeNull()
        ->and($attempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::Succeeded)
        ->and(data_get($attempt->deployment, 'status'))->toBe('failed')
        ->and(data_get($attempt->deployment, 'fallback'))->toBe('manual_requirement_removal')
        ->and($attempt->toJson())->not->toContain('opaque-authorisation-lookup');
    $logger->shouldHaveReceived('warning')
        ->with('capell-marketplace: deployment requirement removal failed', ['composer_names' => [UNINSTALL_PACKAGE_NAME]])
        ->once();
});
