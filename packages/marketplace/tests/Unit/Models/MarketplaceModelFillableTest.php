<?php

declare(strict_types=1);

use Capell\Marketplace\Models\MarketplaceAccountConnectionSession;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Models\MarketplaceInstallFlowSession;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Marketplace\Models\UpdateAdvisorySnapshot;
use Capell\Marketplace\Models\UpdateNoticeDismissal;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;

it('keeps marketplace runtime models closed to arbitrary mass assignment', function (string $modelClass, array $allowedAttributes): void {
    expectMarketplaceModelRejectsUnfillableAttributes($modelClass, $allowedAttributes);
})->with([
    'account connection session' => [
        MarketplaceAccountConnectionSession::class,
        [
            'connection_session_id' => 'mcs_123',
            'state_hash' => hash('sha256', 'state'),
            'code_verifier_hash' => hash('sha256', 'verifier'),
            'code_verifier_encrypted' => 'verifier',
            'claimed_domain' => 'example.test',
            'app_url' => 'https://example.test',
            'callback_url' => 'https://example.test/admin/marketplace/connection/callback',
        ],
    ],
    'install flow session' => [
        MarketplaceInstallFlowSession::class,
        [
            'remote_flow_id' => 'mif_123',
            'selected_extensions' => [['composer_name' => 'vendor/package']],
            'state_hash' => hash('sha256', 'state'),
            'code_verifier_hash' => hash('sha256', 'verifier'),
            'code_verifier_encrypted' => 'verifier',
        ],
    ],
    'marketplace instance' => [
        MarketplaceInstance::class,
        [
            'instance_id' => '00000000-0000-4000-8000-000000000001',
            'signing_secret_encrypted' => 'secret',
        ],
    ],
    'marketplace install attempt' => [
        MarketplaceInstallAttempt::class,
        [
            'extension_slug' => 'forms',
            'beta_acknowledged' => true,
            'policy_evidence' => ['reason' => null],
        ],
    ],
    'update advisory snapshot' => [
        UpdateAdvisorySnapshot::class,
        [
            'source' => 'heartbeat',
            'checked_at' => now(),
        ],
    ],
    'update notice dismissal' => [
        UpdateNoticeDismissal::class,
        [
            'user_id' => 1,
            'notice_id' => 'notice-1',
        ],
    ],
]);

/**
 * @param  class-string<Model>  $modelClass
 * @param  array<string, mixed>  $allowedAttributes
 */
function expectMarketplaceModelRejectsUnfillableAttributes(string $modelClass, array $allowedAttributes): void
{
    $model = new $modelClass;

    expect(fn (): Model => $model->fill($allowedAttributes + [
        'id' => 999,
        'is_admin' => true,
    ]))->toThrow(MassAssignmentException::class);
}
