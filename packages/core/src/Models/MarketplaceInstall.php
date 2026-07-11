<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property int $id
 * @property string $install_id
 * @property string|null $public_key
 * @property string|null $private_key_encrypted
 * @property string|null $site_url
 * @property string|null $environment
 * @property CarbonImmutable|null $registered_at
 * @property CarbonImmutable|null $last_reported_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class MarketplaceInstall extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    protected $table = 'capell_marketplace_installs';

    /** @var list<string> */
    protected $fillable = [
        'install_id',
        'public_key',
        'private_key_encrypted',
        'site_url',
        'environment',
        'registered_at',
        'last_reported_at',
    ];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'private_key_encrypted' => 'encrypted',
            'registered_at' => 'immutable_datetime',
            'last_reported_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
