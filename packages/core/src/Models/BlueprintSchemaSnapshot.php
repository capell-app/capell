<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * Immutable snapshot of blueprint schema metadata captured before schema edits.
 *
 * @property int $id
 * @property int $blueprint_id
 * @property string $blueprint_key
 * @property string $blueprint_type
 * @property CarbonImmutable $taken_at
 * @property string $reason
 * @property string|null $admin_before
 * @property string|null $meta_before
 * @property string|null $type_before
 * @property int|null $actor_id
 * @property array<string, mixed>|null $metadata
 */
final class BlueprintSchemaSnapshot extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    protected $table = 'blueprint_schema_snapshots';

    /** @var list<string> */
    protected $fillable = [
        'blueprint_id',
        'blueprint_key',
        'blueprint_type',
        'taken_at',
        'reason',
        'admin_before',
        'meta_before',
        'type_before',
        'actor_id',
        'metadata',
    ];

    /** @return BelongsTo<Blueprint, $this> */
    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(Blueprint::class);
    }

    public function byteSize(): int
    {
        return strlen((string) $this->admin_before)
            + strlen((string) $this->meta_before)
            + strlen((string) $this->type_before);
    }

    #[Override]
    protected static function booted(): void
    {
        self::updating(fn (): bool => false);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'taken_at' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
