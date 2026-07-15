<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\InstallMarketplaceExtensionAction;
use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Data\MarketplaceInstallRequestData;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceInstallSource;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Support\Facades\Http;

it('records a fail-closed beta policy decision through the real install entry point', function (): void {
    config([
        'app.url' => 'https://release.test',
        'capell-marketplace.instance.id' => 'release-instance',
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api',
        'capell-marketplace.marketplace.webhook_secret' => 'release-secret',
    ]);
    Http::fake([
        'https://marketplace.test/api/extensions/release-beta' => Http::response([
            'data' => [
                'slug' => 'release-beta', 'name' => 'Release Beta', 'composer_name' => 'capell-app/release-beta',
                'kind' => 'tool', 'description' => 'Release contract fixture.', 'price_cents' => 0,
                'is_paid' => false, 'latest_version' => '1.0.0-beta.1', 'maturity' => 'beta',
                'catalogue_role' => 'extension', 'maturity_label' => 'Beta', 'included_with_capell_all' => false,
                'dependencies' => ['requires' => []],
            ],
        ]),
    ]);

    InstallMarketplaceExtensionAction::run(MarketplaceInstallRequestData::make(
        extensionSlug: 'release-beta',
        options: [],
        actor: MarketplaceInstallActorData::system('release-contract'),
        betaAcknowledged: false,
        source: MarketplaceInstallSource::Programmatic,
    ));

    $attempt = MarketplaceInstallAttempt::query()->sole();
    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Blocked)
        ->and($attempt->failure_reason)->toBe('beta_acknowledgement_required')
        ->and($attempt->policy_evidence['selectedMaturity'])->toBe('beta');
});
