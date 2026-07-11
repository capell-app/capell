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
 * @property int $page_url_id
 * @property string $source_url
 * @property string|null $target_url
 * @property bool $has_chain
 * @property bool $has_loop
 * @property int $warning_count
 * @property int $error_count
 * @property CarbonImmutable|null $computed_at
 */
class RedirectHealthSnapshot extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'page_url_id',
        'source_url',
        'target_url',
        'has_chain',
        'has_loop',
        'warning_count',
        'error_count',
        'computed_at',
    ];

    /** @return BelongsTo<PageUrl, $this> */
    public function pageUrl(): BelongsTo
    {
        return $this->belongsTo(PageUrl::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'has_chain' => 'boolean',
            'has_loop' => 'boolean',
            'computed_at' => 'datetime',
        ];
    }
}
