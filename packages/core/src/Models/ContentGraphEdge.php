<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property int $id
 * @property string $source_type
 * @property int $source_id
 * @property string $target_type
 * @property int $target_id
 * @property string $kind
 * @property ContentGraphEdgeStrength $strength
 * @property string $source_package
 * @property int|null $site_id
 * @property int|null $language_id
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * @method static Builder<static>|ContentGraphEdge newModelQuery()
 * @method static Builder<static>|ContentGraphEdge newQuery()
 * @method static Builder<static>|ContentGraphEdge query()
 *
 * @mixin Model
 */
class ContentGraphEdge extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'source_type',
        'source_id',
        'target_type',
        'target_id',
        'kind',
        'strength',
        'source_package',
        'site_id',
        'language_id',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'strength' => ContentGraphEdgeStrength::class,
            'metadata' => 'array',
        ];
    }
}
