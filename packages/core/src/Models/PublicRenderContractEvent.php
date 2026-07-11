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
 * @property string $result
 * @property string|null $reason
 * @property string|null $matched_marker
 * @property string|null $package_name
 * @property string|null $source
 * @property string|null $url_hash
 * @property string|null $path_hash
 * @property string|null $response_hash
 * @property int|null $page_id
 * @property int|null $layout_id
 * @property int|null $theme_id
 * @property array<string, mixed>|null $context
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class PublicRenderContractEvent extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    protected $table = 'capell_public_render_contract_events';

    /** @var list<string> */
    protected $fillable = [
        'result',
        'reason',
        'matched_marker',
        'package_name',
        'source',
        'url_hash',
        'path_hash',
        'response_hash',
        'page_id',
        'layout_id',
        'theme_id',
        'context',
    ];

    #[Override]
    protected static function booted(): void
    {
        self::updating(fn (): bool => false);
        self::deleting(fn (): bool => false);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
