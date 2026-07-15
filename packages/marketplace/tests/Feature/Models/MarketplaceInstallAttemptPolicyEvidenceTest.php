<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\RecordMarketplaceInstallAttemptAction;
use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Data\MarketplaceInstallPolicyEvidenceData;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceInstallSource;
use Carbon\CarbonImmutable;

it('persists consent policy evidence and actor source for every install attempt', function (
    MarketplaceInstallIntentStatus $status,
    bool $acknowledged,
): void {
    $fetchedAt = CarbonImmutable::parse('2026-07-14 12:00:00');
    $evidence = new MarketplaceInstallPolicyEvidenceData(
        listingFingerprint: hash('sha256', 'fresh-listing'),
        listingFetchedAt: $fetchedAt,
        selectedMaturity: 'beta',
        dependencyMaturity: ['capell-app/forms' => 'stable'],
        entitlementAllowed: true,
        compatibilityAllowed: true,
        consentAllowed: $acknowledged,
        reason: $acknowledged ? null : 'beta_acknowledgement_required',
    );

    $attempt = RecordMarketplaceInstallAttemptAction::run(
        extensionSlug: 'beta-tools',
        extensionName: 'Beta Tools',
        composerName: 'capell-app/beta-tools',
        kind: 'tool',
        status: $status,
        betaAcknowledged: $acknowledged,
        policyEvidence: $evidence,
        actor: new MarketplaceInstallActorData('user-42', 'editor@example.test'),
        source: MarketplaceInstallSource::Programmatic,
    )->fresh();

    expect($attempt->beta_acknowledged)->toBe($acknowledged)
        ->and($attempt->policy_evidence['listingFingerprint'])->toBe($evidence->listingFingerprint)
        ->and($attempt->policy_evidence['listingFetchedAt'])->toBe($fetchedAt->toAtomString())
        ->and($attempt->context['install_actor']['identifier'])->toBe('user-42')
        ->and($attempt->context['install_source'])->toBe('programmatic');
})->with([
    'blocked' => [MarketplaceInstallIntentStatus::Blocked, false],
    'queued' => [MarketplaceInstallIntentStatus::Queued, true],
]);
