<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\InstallMarketplaceExtensionAction;
use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Data\MarketplaceInstallRequestData;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceInstallSource;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    test()->actingAsAdmin();
    config([
        'app.url' => 'https://example.test',
        'capell-marketplace.instance.id' => 'instance-123',
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api',
        'capell-marketplace.marketplace.webhook_secret' => 'test-secret',
    ]);
});

it('blocks and records a fresh direct beta without explicit acknowledgement', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions/beta-suite' => Http::response([
            'data' => marketplaceMaturityPayload('beta-suite', 'capell-app/beta-suite', 'beta'),
        ]),
    ]);

    InstallMarketplaceExtensionAction::run(marketplaceMaturityRequest('beta-suite'));

    $attempt = MarketplaceInstallAttempt::query()->sole();
    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Blocked)
        ->and($attempt->failure_reason)->toBe('beta_acknowledgement_required')
        ->and($attempt->beta_acknowledged)->toBeFalse()
        ->and($attempt->policy_evidence['selectedMaturity'])->toBe('beta');
});

it('identifies and blocks the exact transitive beta dependency', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions/stable-suite' => Http::response([
            'data' => marketplaceMaturityPayload(
                'stable-suite',
                'capell-app/stable-suite',
                'stable',
                ['capell-app/middle-suite'],
            ),
        ]),
        'https://marketplace.test/api/extensions/by-composer*' => Http::sequence()
            ->push(['data' => [marketplaceMaturityPayload('middle-suite', 'capell-app/middle-suite', 'stable', ['capell-app/beta-dependency'])]])
            ->push(['data' => [marketplaceMaturityPayload('beta-dependency', 'capell-app/beta-dependency', 'beta')]]),
    ]);

    InstallMarketplaceExtensionAction::run(marketplaceMaturityRequest('stable-suite'));

    $attempt = MarketplaceInstallAttempt::query()->sole();
    expect($attempt->failure_reason)->toBe('beta_dependency_acknowledgement_required')
        ->and($attempt->policy_evidence['blockingDependency'])->toBe('capell-app/beta-dependency')
        ->and($attempt->policy_evidence['dependencyMaturity']['capell-app/beta-dependency'])->toBe('beta');
});

it('allows an explicitly acknowledged fresh beta listing', function (): void {
    Queue::fake();
    Http::fake([
        'https://marketplace.test/api/extensions/beta-suite' => Http::response([
            'data' => marketplaceMaturityPayload('beta-suite', 'capell-app/beta-suite', 'beta'),
        ]),
    ]);

    InstallMarketplaceExtensionAction::run(
        marketplaceMaturityRequest('beta-suite', true),
    );

    $attempt = MarketplaceInstallAttempt::query()->sole();
    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Queued)
        ->and($attempt->beta_acknowledged)->toBeTrue()
        ->and($attempt->policy_evidence['consentAllowed'])->toBeTrue();
});

/** @param list<string> $dependencies */
function marketplaceMaturityPayload(
    string $slug,
    string $composerName,
    string $maturity,
    array $dependencies = [],
): array {
    return [
        'slug' => $slug,
        'name' => str($slug)->headline()->toString(),
        'composer_name' => $composerName,
        'kind' => 'tool',
        'description' => 'Policy test extension.',
        'price_cents' => 0,
        'is_paid' => false,
        'latest_version' => '1.0.0',
        'maturity' => $maturity,
        'catalogue_role' => 'extension',
        'maturity_label' => $maturity === 'stable' ? 'Released' : ucfirst($maturity),
        'included_with_capell_all' => false,
        'dependencies' => ['requires' => $dependencies],
    ];
}

function marketplaceMaturityRequest(string $slug, bool $betaAcknowledged = false): MarketplaceInstallRequestData
{
    return MarketplaceInstallRequestData::make(
        extensionSlug: $slug,
        options: $betaAcknowledged ? ['install_options' => ['beta_acknowledged' => true]] : [],
        actor: MarketplaceInstallActorData::system('marketplace-maturity-test'),
        betaAcknowledged: $betaAcknowledged,
        source: MarketplaceInstallSource::Programmatic,
    );
}
