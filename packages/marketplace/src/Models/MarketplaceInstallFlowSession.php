<?php

declare(strict_types=1);

namespace Capell\Marketplace\Models;

use Capell\Marketplace\Casts\EncryptedString;
use Capell\Marketplace\Enums\MarketplaceInstallFlowSessionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property int $id
 * @property string|null $remote_flow_id
 * @property MarketplaceInstallFlowSessionStatus $status
 * @property int $contract_version
 * @property array<int, array<string, mixed>>|null $selected_extensions
 * @property array<int, array<string, mixed>>|null $quoted_extensions
 * @property int $quoted_price_cents
 * @property string|null $quoted_currency
 * @property array<string, int>|null $remote_entitlement_ids
 * @property array<string, mixed>|null $last_exchange_payload
 * @property array<int, array<string, mixed>>|null $transition_log
 * @property array<string, mixed>|null $install_options
 * @property array<string, mixed>|null $dependency_snapshot
 * @property array<string, mixed>|null $user_context
 * @property string $state_hash
 * @property string $code_verifier_hash
 * @property string $code_verifier_encrypted
 * @property string|null $approval_url
 * @property string|null $return_url
 * @property string|null $last_error
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $failure_metadata
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $redirected_at
 * @property CarbonImmutable|null $returned_at
 * @property CarbonImmutable|null $queued_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class MarketplaceInstallFlowSession extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'remote_flow_id',
        'status',
        'contract_version',
        'selected_extensions',
        'quoted_extensions',
        'quoted_price_cents',
        'quoted_currency',
        'remote_entitlement_ids',
        'last_exchange_payload',
        'transition_log',
        'install_options',
        'dependency_snapshot',
        'user_context',
        'state_hash',
        'code_verifier_hash',
        'code_verifier_encrypted',
        'approval_url',
        'return_url',
        'expires_at',
        'redirected_at',
        'returned_at',
        'queued_at',
        'completed_at',
        'last_error',
        'failure_reason',
        'failure_metadata',
    ];

    protected $table = 'marketplace_install_flow_sessions';

    #[Override]
    protected function casts(): array
    {
        return [
            'status' => MarketplaceInstallFlowSessionStatus::class,
            'contract_version' => 'integer',
            'selected_extensions' => 'array',
            'quoted_extensions' => 'array',
            'quoted_price_cents' => 'integer',
            'remote_entitlement_ids' => 'array',
            'last_exchange_payload' => 'array',
            'transition_log' => 'array',
            'failure_metadata' => 'array',
            'install_options' => 'array',
            'dependency_snapshot' => 'array',
            'user_context' => 'array',
            'code_verifier_encrypted' => EncryptedString::class,
            'expires_at' => 'immutable_datetime',
            'redirected_at' => 'immutable_datetime',
            'returned_at' => 'immutable_datetime',
            'queued_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
