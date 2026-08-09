<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Extensions\ExtensionRemovalCoordinator;
use Capell\Admin\Data\Extensions\ExtensionRemovalRequestData;
use Capell\Admin\Enums\Extensions\ExtensionRemovalMode;
use Capell\Admin\Support\Extensions\InRequestExtensionRemovalCoordinator;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Marketplace\Actions\EvaluateMarketplaceEnvironmentReadinessAction;
use Capell\Marketplace\Data\MarketplaceUninstallOptionsData;
use Capell\Marketplace\Enums\MarketplaceOperationType;
use Capell\Marketplace\Jobs\RunMarketplaceUninstallAttemptJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Support\MarketplaceExtensionRemovalCoordinator;
use Illuminate\Support\Facades\Queue;

const BRIDGED_UNINSTALL_PACKAGE = 'capell-app/bridged-uninstall-package';

beforeEach(function (): void {
    EvaluateMarketplaceEnvironmentReadinessAction::forget();
});

function bridgedRemovalRequest(bool $deletePackage = true, bool $deleteData = false, bool $runLifecycle = true, array $packageNames = [BRIDGED_UNINSTALL_PACKAGE]): ExtensionRemovalRequestData
{
    return new ExtensionRemovalRequestData(
        composerName: BRIDGED_UNINSTALL_PACKAGE,
        packageNames: $packageNames,
        deletePackage: $deletePackage,
        deleteData: $deleteData,
        extensionSlug: 'bridged-uninstall-package',
        extensionName: 'Bridged Uninstall Package',
        kind: 'plugin',
        runLifecycle: $runLifecycle,
    );
}

function registerBridgedPackage(): void
{
    CapellCore::registerPackage(BRIDGED_UNINSTALL_PACKAGE, PackageTypeEnum::Plugin, version: '1.0.0');
    CapellCore::markPackageInstalled(BRIDGED_UNINSTALL_PACKAGE);
}

it('queues the uninstall on a host that can automate it', function (): void {
    Queue::fake();
    registerBridgedPackage();

    $outcome = new MarketplaceExtensionRemovalCoordinator()->queue(bridgedRemovalRequest());

    expect($outcome->accepted)->toBeTrue()
        ->and(new MarketplaceExtensionRemovalCoordinator()->modeFor(BRIDGED_UNINSTALL_PACKAGE))
        ->toBe(ExtensionRemovalMode::Queued);

    $attempt = MarketplaceInstallAttempt::query()->firstOrFail();

    expect($attempt->operation)->toBe(MarketplaceOperationType::Uninstall)
        ->and(capellJsonKeysSorted($attempt->uninstall_options))->toBe(capellJsonKeysSorted([
            'delete_package' => true,
            'delete_data' => false,
            'package_names' => [BRIDGED_UNINSTALL_PACKAGE],
        ]));

    Queue::assertPushed(RunMarketplaceUninstallAttemptJob::class);
});

it('queues confirmed dependents in their safe uninstall order', function (): void {
    Queue::fake();
    registerBridgedPackage();
    $dependent = 'capell-app/bridged-uninstall-dependent';
    CapellCore::registerPackage($dependent, PackageTypeEnum::Plugin, version: '1.0.0');
    CapellCore::getPackage($dependent)->requirements = [BRIDGED_UNINSTALL_PACKAGE];
    CapellCore::markPackageInstalled($dependent);

    $outcome = new MarketplaceExtensionRemovalCoordinator()->queue(bridgedRemovalRequest(
        packageNames: [$dependent, BRIDGED_UNINSTALL_PACKAGE],
    ));
    $options = MarketplaceUninstallOptionsData::fromPayload(
        MarketplaceInstallAttempt::query()->firstOrFail()->uninstall_options,
    );

    expect($outcome->accepted)->toBeTrue()
        ->and($options->packageNames)
        ->toBe([$dependent, BRIDGED_UNINSTALL_PACKAGE]);
});

it('queues package-file deletion after the lifecycle is already uninstalled', function (): void {
    Queue::fake();
    CapellCore::registerPackage(BRIDGED_UNINSTALL_PACKAGE, PackageTypeEnum::Plugin, version: '1.0.0');

    $outcome = new MarketplaceExtensionRemovalCoordinator()->queue(bridgedRemovalRequest(runLifecycle: false));
    $options = MarketplaceUninstallOptionsData::fromPayload(
        MarketplaceInstallAttempt::query()->firstOrFail()->uninstall_options,
    );

    expect($outcome->accepted)->toBeTrue()
        ->and($options->runLifecycle)
        ->toBeFalse();
});

it('discloses manual instructions instead of queueing on a host that cannot automate', function (): void {
    Queue::fake();
    registerBridgedPackage();
    config()->set('capell.release_root_mode', 'immutable');
    EvaluateMarketplaceEnvironmentReadinessAction::forget();

    $coordinator = new MarketplaceExtensionRemovalCoordinator;

    expect($coordinator->modeFor(BRIDGED_UNINSTALL_PACKAGE))->toBe(ExtensionRemovalMode::ManualInstructions)
        ->and($coordinator->manualInstructions(BRIDGED_UNINSTALL_PACKAGE, 'Bridged Uninstall Package'))
        ->toContain('capell:extension-uninstall')
        ->toContain('composer remove')
        ->toContain(BRIDGED_UNINSTALL_PACKAGE);

    Queue::assertNothingPushed();
});

it('reports a refusal as an answer rather than throwing when the uninstall cannot be queued', function (): void {
    Queue::fake();
    // Never registered, so the package is not installed.

    $outcome = new MarketplaceExtensionRemovalCoordinator()->queue(bridgedRemovalRequest());

    expect($outcome->accepted)->toBeFalse()
        ->and($outcome->body)->toContain(BRIDGED_UNINSTALL_PACKAGE)
        ->and(MarketplaceInstallAttempt::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('keeps the in-request path for a site with no marketplace coordinator bound', function (): void {
    // The admin default, which is what a Capell install without the
    // marketplace package resolves.
    app()->bind(ExtensionRemovalCoordinator::class, InRequestExtensionRemovalCoordinator::class);

    expect(resolve(ExtensionRemovalCoordinator::class)->modeFor(BRIDGED_UNINSTALL_PACKAGE))
        ->toBe(ExtensionRemovalMode::InRequest);
});

it('is the coordinator the admin panel resolves once the marketplace bridge has registered', function (): void {
    app()->bind(ExtensionRemovalCoordinator::class, MarketplaceExtensionRemovalCoordinator::class);

    expect(resolve(ExtensionRemovalCoordinator::class))
        ->toBeInstanceOf(MarketplaceExtensionRemovalCoordinator::class);
});
