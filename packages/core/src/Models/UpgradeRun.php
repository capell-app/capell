<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Enums\Upgrade\UpgradeRunStatus;
use Capell\Core\Enums\Upgrade\UpgradeStage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * @property int $id
 * @property UpgradeRunStatus $status
 * @property bool $dry_run
 * @property int|null $user_id
 * @property array<string, mixed>|null $options
 * @property list<string>|null $manual_commands
 * @property list<string>|null $readiness_warnings
 * @property list<string>|null $readiness_errors
 * @property UpgradeStage|null $current_stage
 * @property string|null $failure_reason
 * @property string|null $output_excerpt
 * @property CarbonImmutable|null $queued_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class UpgradeRun extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    protected $table = 'capell_upgrade_runs';

    /** @var list<string> */
    protected $fillable = [
        'status',
        'dry_run',
        'user_id',
        'options',
        'manual_commands',
        'readiness_warnings',
        'readiness_errors',
        'current_stage',
        'failure_reason',
        'output_excerpt',
        'queued_at',
        'started_at',
        'finished_at',
    ];

    /** @return HasMany<UpgradeRunEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(UpgradeRunEvent::class, 'upgrade_run_id');
    }

    #[Override]
    protected static function booted(): void
    {
        self::deleting(fn (): bool => false);
    }

    /** @param  Builder<self>  $query */
    protected function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [
            UpgradeRunStatus::Queued->value,
            UpgradeRunStatus::Running->value,
        ]);
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'status' => UpgradeRunStatus::class,
            'dry_run' => 'bool',
            'options' => 'array',
            'manual_commands' => 'array',
            'readiness_warnings' => 'array',
            'readiness_errors' => 'array',
            'current_stage' => UpgradeStage::class,
            'queued_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
