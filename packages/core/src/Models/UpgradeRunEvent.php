<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Enums\Upgrade\UpgradeRunEventLevel;
use Capell\Core\Enums\Upgrade\UpgradeStage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property int $upgrade_run_id
 * @property UpgradeRunEventLevel $level
 * @property UpgradeStage|null $stage
 * @property string $message
 * @property array<string, mixed>|null $context
 * @property string|null $output_excerpt
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class UpgradeRunEvent extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    protected $table = 'capell_upgrade_run_events';

    /** @var list<string> */
    protected $fillable = [
        'upgrade_run_id',
        'level',
        'stage',
        'message',
        'context',
        'output_excerpt',
        'occurred_at',
    ];

    /** @return BelongsTo<UpgradeRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(UpgradeRun::class, 'upgrade_run_id');
    }

    #[Override]
    protected static function booted(): void
    {
        self::updating(fn (): bool => false);
        self::deleting(fn (): bool => false);
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'level' => UpgradeRunEventLevel::class,
            'stage' => UpgradeStage::class,
            'context' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
