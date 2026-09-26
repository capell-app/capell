<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Enums\Reporting\IncidentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $fingerprint
 * @property array<string, mixed> $signal
 * @property IncidentStatus $status
 * @property string|null $owner
 * @property string|null $backup
 * @property bool $health
 * @property bool $delivery_failed
 * @property CarbonImmutable|null $checked_at
 * @property array<string, string> $deliveries
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $last_attempt_at
 * @property CarbonImmutable|null $acknowledged_at
 * @property CarbonImmutable|null $escalated_at
 * @property CarbonImmutable|null $resolved_at
 * @property string|null $acknowledged_by
 * @property string|null $claim_token
 * @property int $claim_until
 */
final class ReportingIncident extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'fingerprint';

    protected $table = 'capell_reporting_incidents';

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'signal' => 'array',
            'status' => IncidentStatus::class,
            'health' => 'boolean',
            'delivery_failed' => 'boolean',
            'checked_at' => 'immutable_datetime',
            'deliveries' => 'array',
            'opened_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
            'escalated_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'claim_until' => 'integer',
        ];
    }
}
