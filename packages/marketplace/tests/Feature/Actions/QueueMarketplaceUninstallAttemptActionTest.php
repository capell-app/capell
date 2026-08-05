<?php

declare(strict_types=1);

use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Marketplace\Actions\AssertNoActiveMarketplaceOperationAction;
use Capell\Marketplace\Actions\QueueMarketplaceUninstallAttemptAction;
use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Data\MarketplaceUninstallOptionsData;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceInstallSource;
use Capell\Marketplace\Enums\MarketplaceOperationType;
use Capell\Marketplace\Jobs\RunMarketplaceUninstallAttemptJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

const QUEUED_UNINSTALL_PACKAGE = 'capell-app/queued-uninstall-package';

function registerQueueableUninstallPackage(string $name = QUEUED_UNINSTALL_PACKAGE): void
{
    CapellCore::registerPackage($name, PackageTypeEnum::Plugin, version: '1.0.0');
    CapellCore::markPackageInstalled($name);
}

function queueUninstallAttempt(
    bool $deletePackage = false,
    bool $deleteData = false,
    ?string $idempotencyKey = null,
    array $packageNames = [],
): MarketplaceInstallAttempt {
    return QueueMarketplaceUninstallAttemptAction::run(
        composerName: QUEUED_UNINSTALL_PACKAGE,
        extensionSlug: 'queued-uninstall-package',
        extensionName: 'Queued Uninstall Package',
        kind: 'plugin',
        options: new MarketplaceUninstallOptionsData(
            deletePackage: $deletePackage,
            deleteData: $deleteData,
            packageNames: $packageNames,
        ),
        actor: MarketplaceInstallActorData::system('uninstall-test'),
        source: MarketplaceInstallSource::LocalUi,
        idempotencyKey: $idempotencyKey,
    );
}

it('records the operation as an uninstall and dispatches the uninstall job', function (): void {
    Queue::fake();
    registerQueueableUninstallPackage();

    $attempt = queueUninstallAttempt(deletePackage: true, deleteData: true);

    expect($attempt->operation)->toBe(MarketplaceOperationType::Uninstall)
        ->and($attempt->status)->toBe(MarketplaceInstallIntentStatus::Queued)
        ->and(capellJsonKeysSorted($attempt->uninstall_options))
        ->toBe(capellJsonKeysSorted(['delete_package' => true, 'delete_data' => true]));

    Queue::assertPushed(RunMarketplaceUninstallAttemptJob::class);
});

it('carries the operator options through the queue unchanged', function (bool $deletePackage, bool $deleteData): void {
    Queue::fake();
    registerQueueableUninstallPackage();

    $attempt = queueUninstallAttempt(deletePackage: $deletePackage, deleteData: $deleteData);
    $restored = MarketplaceUninstallOptionsData::fromPayload($attempt->refresh()->uninstall_options);

    expect($restored->deletePackage)->toBe($deletePackage)
        ->and($restored->deleteData)->toBe($deleteData);
})->with([
    'neither' => [false, false],
    'package only' => [true, false],
    'data only' => [false, true],
    'both' => [true, true],
]);

it('returns the existing attempt for a repeated idempotency key instead of queueing twice', function (): void {
    Queue::fake();
    registerQueueableUninstallPackage();

    $first = queueUninstallAttempt(idempotencyKey: 'uninstall-once');
    $second = queueUninstallAttempt(idempotencyKey: 'uninstall-once');

    expect($second->getKey())->toBe($first->getKey())
        ->and(MarketplaceInstallAttempt::query()->count())->toBe(1);

    Queue::assertPushed(RunMarketplaceUninstallAttemptJob::class, 1);
});

it('refuses to queue an uninstall while any other operation is active for the package', function (string $activeStatus): void {
    Queue::fake();
    registerQueueableUninstallPackage();

    MarketplaceInstallAttempt::query()->create([
        'composer_name' => QUEUED_UNINSTALL_PACKAGE,
        'extension_slug' => 'queued-uninstall-package',
        'extension_name' => 'Queued Uninstall Package',
        'kind' => 'plugin',
        'status' => $activeStatus,
        // Deliberately an update, not an uninstall: the guard is about the
        // package, not about which operation is holding it.
        'operation' => MarketplaceOperationType::Update,
        'queued_at' => now(),
    ]);

    expect(fn (): MarketplaceInstallAttempt => queueUninstallAttempt())
        ->toThrow(ValidationException::class);

    Queue::assertNothingPushed();
})->with([
    'queued' => [MarketplaceInstallIntentStatus::Queued->value],
    'running' => [MarketplaceInstallIntentStatus::Running->value],
    'cancel requested' => [MarketplaceInstallIntentStatus::CancelRequested->value],
]);

it('refuses an uninstall of a package that is not installed', function (): void {
    Queue::fake();

    expect(fn (): MarketplaceInstallAttempt => queueUninstallAttempt())
        ->toThrow(ValidationException::class);

    Queue::assertNothingPushed();
});

it('refuses an operation on any dependent covered by an active multi-package uninstall', function (): void {
    $dependent = 'capell-app/queued-uninstall-dependent';

    MarketplaceInstallAttempt::query()->create([
        'composer_name' => QUEUED_UNINSTALL_PACKAGE,
        'extension_slug' => 'queued-uninstall-package',
        'extension_name' => 'Queued Uninstall Package',
        'kind' => 'plugin',
        'status' => MarketplaceInstallIntentStatus::Queued,
        'operation' => MarketplaceOperationType::Uninstall,
        'context' => ['affected_package_names' => [$dependent, QUEUED_UNINSTALL_PACKAGE]],
        'queued_at' => now(),
    ]);

    expect(fn (): mixed => AssertNoActiveMarketplaceOperationAction::run($dependent))
        ->toThrow(ValidationException::class, $dependent);
});

it('refuses an uninstall while another installed extension depends on the package', function (): void {
    Queue::fake();
    registerQueueableUninstallPackage();
    registerQueueableUninstallPackage('capell-app/dependent-extension');
    CapellCore::getPackage('capell-app/dependent-extension')->requirements = [QUEUED_UNINSTALL_PACKAGE];

    expect(fn (): MarketplaceInstallAttempt => queueUninstallAttempt())
        ->toThrow(ValidationException::class, 'capell-app/dependent-extension');

    Queue::assertNothingPushed();
});

it('refuses to delete the package files when the release root refuses the write', function (): void {
    Queue::fake();
    registerQueueableUninstallPackage();
    config()->set('capell.release_root_mode', 'immutable');

    expect(fn (): MarketplaceInstallAttempt => queueUninstallAttempt(deletePackage: true))
        ->toThrow(ValidationException::class);

    Queue::assertNothingPushed();
});

it('records no attempt at all when the host cannot run an automated operation', function (): void {
    Queue::fake();
    registerQueueableUninstallPackage();
    config()->set('capell.release_root_mode', 'immutable');

    expect(fn (): MarketplaceInstallAttempt => queueUninstallAttempt())
        ->toThrow(ValidationException::class);

    // A host that cannot automate has not failed this uninstall — there was
    // never an uninstall to fail, so nothing belongs on the operations page.
    expect(MarketplaceInstallAttempt::query()->count())->toBe(0);
});
