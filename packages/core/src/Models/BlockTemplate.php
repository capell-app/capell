<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Database\Factories\BlockTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property array<int, array<string, mixed>> $blocks
 * @property bool $enabled
 *
 * @method static Builder<static>|BlockTemplate enabled()
 * @method static Builder<static>|BlockTemplate newModelQuery()
 * @method static Builder<static>|BlockTemplate newQuery()
 * @method static Builder<static>|BlockTemplate query()
 *
 * @mixin Model
 */
final class BlockTemplate extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    protected static string $factory = BlockTemplateFactory::class;

    /** @var list<string> */
    protected $fillable = [
        'key',
        'name',
        'description',
        'blocks',
        'enabled',
    ];

    /**
     * @param  Builder<self>  $query
     */
    protected function scopeEnabled(Builder $query): void
    {
        $query->where('enabled', true);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'blocks' => 'array',
            'enabled' => 'boolean',
        ];
    }
}
