<?php

declare(strict_types=1);

use Capell\Admin\Actions\Extensions\BuildExtensionUpdateReadinessAction;
use Capell\Admin\Data\Extensions\ExtensionUpdateReadinessData;
use Capell\Core\Enums\ExtensionAutoUpdatePolicyEnum;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Marketplace\Actions\QueueMarketplaceAutoUpdatesAction;
use Capell\Marketplace\Actions\QueueMarketplaceBulkUpdateAction;
use Capell\Marketplace\Actions\UpdateMarketplaceExtensionAction;
use Capell\Marketplace\Contracts\MarketplaceComposerRunner;
use Capell\Marketplace\Contracts\MarketplaceInstalledPackageVersionResolver;
use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceInstallSource;
use Capell\Marketplace\Enums\MarketplaceOperationType;
use Capell\Marketplace\Jobs\RunMarketplaceUpdateAttemptJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Marketplace\Tests\Support\RecordingMarketplaceComposerRunner;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

const PINNED_PACKAGE = 'capell-app/pinned-suite';

function pinnedPackageListing(bool $paid = false): array
{
    return [
        'slug' => 'pinned-suite',
        'name' => 'Pinned Suite',
        'composer_name' => PINNED_PACKAGE,
        'kind' => 'plugin',
        'price_cents' => $paid ? 4900 : 0,
        'is_paid' => $paid,
        'latest_version' => '2.4.0',
        'install_eligibility' => [
            'state' => 'authorized',
            'can_install' => true,
            'can_update' => true,
            'can_run_existing' => true,
        ],
    ];
}

function installPinnedPackage(): void
{
    CapellCore::registerPackage(PINNED_PACKAGE, version: '2.1.0');
    CapellCore::forcePackageInstalled(PINNED_PACKAGE);
    app()->instance(MarketplaceInstalledPackageVersionResolver::class, new readonly class implements MarketplaceInstalledPackageVersionResolver
    {
        #[Override]
        public function prettyVersion(string $composerName): ?string
        {
            return $composerName === PINNED_PACKAGE ? '2.1.0' : null;
        }
    });
}

function fakePaidMarketplaceUpdate(string $versionConstraint): void
{
    MarketplaceInstance::query()->create([
        'instance_id' => 'instance-pinning',
        'signing_secret_encrypted' => 'secret-value',
        'last_heartbeat_at' => now(),
    ]);
    config()->set('capell-marketplace.marketplace.base_url', 'https://marketplace.test/api');

    Http::fake([
        'marketplace.test/api/extensions/by-composer*' => Http::response(['data' => [pinnedPackageListing(paid: true)]]),
        'marketplace.test/api/extensions/upgrade-authorization' => Http::response(['data' => [
            'composer_name' => PINNED_PACKAGE,
            'version_constraint' => $versionConstraint,
            'composer_auth' => ['http-basic' => ['packages.test' => ['username' => 'token']]],
        ]]),
    ]);
}

it('queues the exact approved version when the auto-updater takes a free update', function (): void {
    Queue::fake();
    installPinnedPackage();
    Http::fake(['*extensions/by-composer*' => Http::response(['data' => [pinnedPackageListing()]])]);

    CapellExtension::query()->create([
        'composer_name' => PINNED_PACKAGE,
        'name' => PINNED_PACKAGE,
        'status' => ExtensionStatusEnum::Enabled,
        'auto_update_policy' => ExtensionAutoUpdatePolicyEnum::Minor,
    ]);
    app()->instance(BuildExtensionUpdateReadinessAction::class, new readonly class
    {
        /** @return list<ExtensionUpdateReadinessData> */
        public function handle(): array
        {
            return [new ExtensionUpdateReadinessData(PINNED_PACKAGE, 'minor_ready', '2.1.0', '2.4.0')];
        }
    });

    $result = QueueMarketplaceAutoUpdatesAction::run();
    $attempt = MarketplaceInstallAttempt::query()->sole();

    expect($result->queuedAttemptIds)->toHaveKey(PINNED_PACKAGE)
        ->and($attempt->version_constraint)->toBe('2.4.0')
        ->and($attempt->composer_command)->toBe("composer require 'capell-app/pinned-suite:2.4.0'");
    Queue::assertPushed(RunMarketplaceUpdateAttemptJob::class, 1);
});

it('keeps the bounded range for an update an operator asks for themselves', function (): void {
    Queue::fake();
    installPinnedPackage();
    Http::fake(['*extensions/by-composer*' => Http::response(['data' => [pinnedPackageListing()]])]);

    $attempt = UpdateMarketplaceExtensionAction::run(
        composerName: PINNED_PACKAGE,
        actor: MarketplaceInstallActorData::system('operator-update'),
        source: MarketplaceInstallSource::TableHelper,
    );

    expect($attempt->version_constraint)->toBe('^2.4.0');
});

it('refuses an unattended update when the marketplace authorises a caret range, before queueing anything', function (string $constraint): void {
    Queue::fake();
    installPinnedPackage();
    fakePaidMarketplaceUpdate($constraint);

    expect(fn (): mixed => UpdateMarketplaceExtensionAction::run(
        composerName: PINNED_PACKAGE,
        actor: MarketplaceInstallActorData::system('marketplace-auto-update'),
        source: MarketplaceInstallSource::Scheduler,
    ))->toThrow(ValidationException::class, (string) __('capell-marketplace::marketplace.errors.unattended_update_not_pinned'));

    expect(MarketplaceInstallAttempt::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'caret' => ['^2.4.0'],
    'tilde' => ['~2.4'],
    'range' => ['>=2.4.0 <3.0'],
    'a different exact version than the one advertised' => ['2.9.9'],
]);

it('accepts an unattended authorisation that pins the advertised version', function (string $constraint): void {
    Queue::fake();
    installPinnedPackage();
    fakePaidMarketplaceUpdate($constraint);

    $attempt = UpdateMarketplaceExtensionAction::run(
        composerName: PINNED_PACKAGE,
        actor: MarketplaceInstallActorData::system('marketplace-auto-update'),
        source: MarketplaceInstallSource::Scheduler,
    );

    expect($attempt->version_constraint)->toBe($constraint);
})->with(['bare version' => ['2.4.0'], 'double equals' => ['==2.4.0']]);

it('reports the refusal against the package when the auto-updater is handed a range, so the operator can read it', function (): void {
    Queue::fake();
    installPinnedPackage();
    fakePaidMarketplaceUpdate('^2.4.0');

    $result = QueueMarketplaceBulkUpdateAction::run(
        composerNames: [PINNED_PACKAGE],
        actor: MarketplaceInstallActorData::system('marketplace-auto-update'),
        source: MarketplaceInstallSource::Scheduler,
    );

    expect($result->queuedAttemptIds)->toBe([])
        ->and($result->skipped[PINNED_PACKAGE] ?? null)->toBe((string) __('capell-marketplace::marketplace.errors.unattended_update_not_pinned'));
});

it('does not run Composer for a queued unattended update that carries a range', function (): void {
    $runner = new RecordingMarketplaceComposerRunner('updated');
    app()->instance(MarketplaceComposerRunner::class, $runner);
    installPinnedPackage();

    $attempt = MarketplaceInstallAttempt::query()->create([
        'composer_name' => PINNED_PACKAGE,
        'extension_slug' => 'pinned-suite',
        'extension_name' => 'Pinned Suite',
        'kind' => 'plugin',
        'status' => MarketplaceInstallIntentStatus::Queued,
        'operation' => MarketplaceOperationType::Update,
        'version_constraint' => '^2.4.0',
        'context' => ['install_source' => MarketplaceInstallSource::Scheduler->value, 'update_from_version' => '2.1.0'],
        'queued_at' => now(),
    ]);

    new RunMarketplaceUpdateAttemptJob((int) $attempt->getKey())->handle($runner);

    $attempt->refresh();

    expect($runner->ran())->toBeFalse()
        ->and($attempt->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($attempt->failure_reason)->toBe((string) __('capell-marketplace::marketplace.errors.unattended_update_not_pinned'));
});
