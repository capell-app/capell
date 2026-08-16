<?php

declare(strict_types=1);

use Capell\Admin\Actions\Extensions\BuildExtensionUpdateReadinessAction;
use Capell\Admin\Data\Extensions\ExtensionUpdateReadinessData;
use Capell\Core\Enums\ExtensionAutoUpdatePolicyEnum;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Models\CapellExtension;
use Capell\Marketplace\Actions\RunMarketplaceHeartbeatAction;
use Capell\Marketplace\Data\PhoneHomeResultData;
use Capell\Marketplace\Support\MarketplaceWorkerHeartbeat;

afterEach(function (): void {
    MarketplaceWorkerHeartbeat::forget();
});

it('stops before queueing when the marketplace heartbeat fails', function (): void {
    RunMarketplaceHeartbeatAction::shouldRun()
        ->once()
        ->andReturn(new PhoneHomeResultData(false));

    artisanCommand('capell:marketplace:auto-update')->assertFailed();
});

it('runs a dry update preview without requiring a fresh worker heartbeat', function (): void {
    marketplaceCommandExtension('vendor/eligible');
    fakeMarketplaceCommandReadiness([
        new ExtensionUpdateReadinessData('vendor/eligible', 'patch_ready', '1.0.0', '1.0.1'),
    ]);
    RunMarketplaceHeartbeatAction::shouldRun()
        ->once()
        ->andReturn(new PhoneHomeResultData(true));

    artisanCommand('capell:marketplace:auto-update', ['--dry-run' => true])->assertSuccessful();
});

it('refuses unattended updates when no worker heartbeat is fresh', function (): void {
    RunMarketplaceHeartbeatAction::shouldRun()
        ->once()
        ->andReturn(new PhoneHomeResultData(true));

    artisanCommand('capell:marketplace:auto-update')->assertFailed();
});

it('reports successful queueing only when every requested update is queued', function (): void {
    RunMarketplaceHeartbeatAction::shouldRun()
        ->once()
        ->andReturn(new PhoneHomeResultData(true));

    MarketplaceWorkerHeartbeat::record();

    artisanCommand('capell:marketplace:auto-update')->assertSuccessful();
});

it('returns failure when unattended queueing leaves an update skipped', function (): void {
    marketplaceCommandExtension('vendor/blocked');
    fakeMarketplaceCommandReadiness([
        new ExtensionUpdateReadinessData('vendor/blocked', 'patch_ready', '1.0.0', '1.0.1-beta.1'),
    ]);
    MarketplaceWorkerHeartbeat::record();
    RunMarketplaceHeartbeatAction::shouldRun()
        ->once()
        ->andReturn(new PhoneHomeResultData(true));

    artisanCommand('capell:marketplace:auto-update')->assertFailed();
});

function marketplaceCommandExtension(string $composerName): CapellExtension
{
    return CapellExtension::query()->create([
        'composer_name' => $composerName,
        'name' => $composerName,
        'status' => ExtensionStatusEnum::Enabled,
        'auto_update_policy' => ExtensionAutoUpdatePolicyEnum::Patch,
    ]);
}

/** @param list<ExtensionUpdateReadinessData> $readiness */
function fakeMarketplaceCommandReadiness(array $readiness): void
{
    app()->instance(BuildExtensionUpdateReadinessAction::class, new readonly class($readiness)
    {
        /** @param list<ExtensionUpdateReadinessData> $readiness */
        public function __construct(private array $readiness) {}

        /** @return list<ExtensionUpdateReadinessData> */
        public function handle(): array
        {
            return $this->readiness;
        }
    });
}
