<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Concerns\HasCapellTable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * One server's readings for one hour. Holds the node name, load, memory, swap,
 * queue depth and command names only.
 *
 * @property int $id
 * @property string $node
 * @property int $hour_start Unix time the hour began.
 * @property int $cores
 * @property int $samples
 * @property float $load5_sum
 * @property float $load5_peak
 * @property float|null $memory_available_min_percent
 * @property float|null $swap_used_peak_percent
 * @property int|null $queue_depth_peak
 * @property list<array{command: string, seconds: float}>|null $busiest_tasks
 * @property int $last_sample_at
 */
final class HostLoadRollup extends Model
{
    use HasCapellTable;

    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    protected $table = 'capell_host_load_rollups';

    /** @var list<string> */
    protected $fillable = [
        'node',
        'hour_start',
        'cores',
        'samples',
        'load5_sum',
        'load5_peak',
        'memory_available_min_percent',
        'swap_used_peak_percent',
        'queue_depth_peak',
        'busiest_tasks',
        'last_sample_at',
    ];

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'hour_start' => 'integer',
            'cores' => 'integer',
            'samples' => 'integer',
            'load5_sum' => 'float',
            'load5_peak' => 'float',
            'memory_available_min_percent' => 'float',
            'swap_used_peak_percent' => 'float',
            'queue_depth_peak' => 'integer',
            'busiest_tasks' => 'array',
            'last_sample_at' => 'integer',
        ];
    }
}
