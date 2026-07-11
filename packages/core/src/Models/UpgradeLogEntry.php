<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Enums\Upgrade\UpgradeStepStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $type
 * @property string $key
 * @property ?string $package
 * @property string $status
 * @property CarbonImmutable $ran_at
 * @property ?array<string, mixed> $meta
 */
class UpgradeLogEntry extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    protected $table = 'capell_upgrade_log';

    /** @var list<string> */
    protected $fillable = [
        'type',
        'key',
        'package',
        'status',
        'ran_at',
        'meta',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'ran_at' => 'immutable_datetime',
        'meta' => 'array',
    ];

    public function metaGet(string $path, mixed $default = null): mixed
    {
        return data_get($this->meta ?? [], $path, $default);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeSteps(Builder $query): Builder
    {
        return $query->where('type', 'step');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeVersionSnapshots(Builder $query): Builder
    {
        return $query->where('type', 'version_snapshot');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', UpgradeStepStatus::Success->value);
    }
}
