<?php

declare(strict_types=1);

namespace Capell\Marketplace\Models;

use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property int $id
 * @property string $composer_name
 * @property string $extension_slug
 * @property string $extension_name
 * @property string $kind
 * @property MarketplaceInstallIntentStatus $status
 * @property string $composer_command
 * @property string|null $version_constraint
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class MarketplaceInstallIntent extends Model
{
    use HasFactory;

    protected $fillable = [
        'composer_name',
        'extension_slug',
        'extension_name',
        'kind',
        'status',
        'composer_command',
        'version_constraint',
        'metadata',
        'resolved_at',
    ];

    protected $table = 'marketplace_install_intents';

    #[Override]
    protected function casts(): array
    {
        return [
            'status' => MarketplaceInstallIntentStatus::class,
            'metadata' => 'array',
            'resolved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
