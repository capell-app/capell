<?php

declare(strict_types=1);

use Capell\Core\Data\Marketplace\ExtensionLicenceDecisionData;
use Capell\Core\Enums\ExtensionLicenceStatus;

it('parses an extension licence decision from an api response', function (): void {
    $decision = ExtensionLicenceDecisionData::fromApiResponse([
        'licence_status' => 'active',
        'can_view_private_docs' => true,
        'can_download' => true,
        'can_install' => true,
        'can_update' => true,
        'can_rate' => true,
        'can_comment' => true,
        'runtime_allowed' => true,
        'reason' => null,
        'verified_site_id' => 'site_123',
        'install_id' => 'inst_123',
        'signed_activation' => ['activation_id' => 'act_123', 'signature' => 'sig'],
    ]);

    expect($decision->licenceStatus)->toBe(ExtensionLicenceStatus::Active)
        ->and($decision->canDownload)->toBeTrue()
        ->and($decision->runtimeAllowed)->toBeTrue()
        ->and($decision->verifiedSiteId)->toBe('site_123')
        ->and($decision->toArray())->toBe([
            'licence_status' => 'active',
            'can_view_private_docs' => true,
            'can_download' => true,
            'can_install' => true,
            'can_update' => true,
            'can_rate' => true,
            'can_comment' => true,
            'runtime_allowed' => true,
            'reason' => null,
            'verified_site_id' => 'site_123',
            'install_id' => 'inst_123',
            'signed_activation' => ['activation_id' => 'act_123', 'signature' => 'sig'],
        ]);
});

it('defaults unknown extension licence decisions to no access', function (): void {
    $decision = ExtensionLicenceDecisionData::fromApiResponse([
        'licence_status' => 'unexpected',
        'can_download' => null,
        'runtime_allowed' => null,
        'reason' => ['invalid'],
        'signed_activation' => 'invalid',
    ]);

    expect($decision->licenceStatus)->toBe(ExtensionLicenceStatus::None)
        ->and($decision->canViewPrivateDocs)->toBeFalse()
        ->and($decision->canDownload)->toBeFalse()
        ->and($decision->canInstall)->toBeFalse()
        ->and($decision->canUpdate)->toBeFalse()
        ->and($decision->canRate)->toBeFalse()
        ->and($decision->canComment)->toBeFalse()
        ->and($decision->runtimeAllowed)->toBeFalse()
        ->and($decision->reason)->toBeNull()
        ->and($decision->signedActivation)->toBe([]);
});
