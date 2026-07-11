<?php

declare(strict_types=1);

namespace Capell\Marketplace\Models;

use Capell\Marketplace\Casts\EncryptedString;
use Capell\Marketplace\Enums\MarketplaceAccountConnectionSessionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property int $id
 * @property string|null $connection_session_id
 * @property string $state_hash
 * @property string $code_verifier_hash
 * @property string $code_verifier_encrypted
 * @property string $claimed_domain
 * @property string $app_url
 * @property string $callback_url
 * @property MarketplaceAccountConnectionSessionStatus $status
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $completed_at
 * @property string|null $last_error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class MarketplaceAccountConnectionSession extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'connection_session_id',
        'state_hash',
        'code_verifier_hash',
        'code_verifier_encrypted',
        'claimed_domain',
        'app_url',
        'callback_url',
        'status',
        'expires_at',
        'completed_at',
        'last_error',
    ];

    protected $table = 'marketplace_account_connection_sessions';

    #[Override]
    protected function casts(): array
    {
        return [
            'code_verifier_encrypted' => EncryptedString::class,
            'status' => MarketplaceAccountConnectionSessionStatus::class,
            'expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
