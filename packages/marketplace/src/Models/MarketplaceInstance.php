<?php

declare(strict_types=1);

namespace Capell\Marketplace\Models;

use Capell\Marketplace\Casts\EncryptedString;
use Capell\Marketplace\Enums\MarketplaceConnectionMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property int $id
 * @property string $instance_id
 * @property string $signing_secret_encrypted
 * @property MarketplaceConnectionMode $connection_mode
 * @property string|null $account_id
 * @property string|null $account_name
 * @property string|null $account_email
 * @property CarbonImmutable|null $account_email_verified_at
 * @property array<string, mixed>|null $connection_metadata
 * @property CarbonImmutable|null $connected_at
 * @property CarbonImmutable|null $last_heartbeat_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class MarketplaceInstance extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'instance_id',
        'signing_secret_encrypted',
        'connection_mode',
        'account_id',
        'account_name',
        'account_email',
        'account_email_verified_at',
        'connection_metadata',
        'connected_at',
        'last_heartbeat_at',
    ];

    protected $table = 'marketplace_instances';

    #[Override]
    protected function casts(): array
    {
        return [
            'signing_secret_encrypted' => EncryptedString::class,
            'connection_mode' => MarketplaceConnectionMode::class,
            'account_email_verified_at' => 'immutable_datetime',
            'connection_metadata' => 'array',
            'connected_at' => 'immutable_datetime',
            'last_heartbeat_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
