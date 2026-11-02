<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Concerns\HasCapellTable;
use Capell\Core\Enums\Security\SecurityRemediationOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * Append-only record of one remediation attempt: who or what ran it, what it
 * changed, and how it ended.
 *
 * @property int $id
 * @property int|null $finding_id
 * @property string $weakness_id
 * @property string $remediation_class
 * @property string $actor
 * @property SecurityRemediationOutcome $outcome
 * @property string $message
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class SecurityRemediationRun extends Model
{
    use HasCapellTable;

    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    public const string ACTOR_SCHEDULER = 'system:scheduler';

    protected $table = 'capell_security_remediation_runs';

    /** @var list<string> */
    protected $fillable = [
        'finding_id',
        'weakness_id',
        'remediation_class',
        'actor',
        'outcome',
        'message',
        'before',
        'after',
        'started_at',
        'finished_at',
    ];

    /** @return BelongsTo<SecurityWeaknessFinding, $this> */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(SecurityWeaknessFinding::class, 'finding_id');
    }

    #[Override]
    protected static function booted(): void
    {
        self::deleting(fn (): bool => false);
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'outcome' => SecurityRemediationOutcome::class,
            'before' => 'array',
            'after' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
