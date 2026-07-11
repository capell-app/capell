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
 * Immutable snapshot of layout authoring data captured before destructive
 * layout operations such as deletion.
 *
 * @property int $id
 * @property int $layout_id
 * @property int|null $site_id
 * @property int|null $theme_id
 * @property CarbonImmutable $taken_at
 * @property string $reason
 * @property string|null $containers_before
 * @property string|null $admin_before
 * @property string|null $meta_before
 * @property string|null $elements_before
 * @property int|null $actor_id
 * @property array<string, mixed>|null $metadata
 */
final class LayoutContentSnapshot extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    protected $table = 'layout_content_snapshots';

    /** @var list<string> */
    protected $fillable = [
        'layout_id',
        'site_id',
        'theme_id',
        'taken_at',
        'reason',
        'containers_before',
        'admin_before',
        'meta_before',
        'elements_before',
        'actor_id',
        'metadata',
    ];

    /** @return BelongsTo<Layout, $this> */
    public function layout(): BelongsTo
    {
        return $this->belongsTo(Layout::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<Theme, $this> */
    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }

    public function byteSize(): int
    {
        return strlen((string) $this->containers_before)
            + strlen((string) $this->admin_before)
            + strlen((string) $this->meta_before)
            + strlen((string) $this->elements_before);
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
