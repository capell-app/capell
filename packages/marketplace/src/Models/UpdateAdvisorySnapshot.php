<?php

declare(strict_types=1);

namespace Capell\Marketplace\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property int $id
 * @property string $source
 * @property CarbonImmutable $checked_at
 * @property string|null $capell_version
 * @property array<string, mixed>|null $updates
 * @property array<string, mixed>|null $advisories
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class UpdateAdvisorySnapshot extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'source',
        'checked_at',
        'capell_version',
        'updates',
        'advisories',
        'metadata',
    ];

    protected $table = 'marketplace_update_advisory_snapshots';

    public static function latestSnapshot(): ?self
    {
        return self::query()
            ->latest('checked_at')
            ->orderByDesc('id')
            ->first();
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'checked_at' => 'immutable_datetime',
            'updates' => 'array',
            'advisories' => 'array',
            'metadata' => 'array',
        ];
    }
}
