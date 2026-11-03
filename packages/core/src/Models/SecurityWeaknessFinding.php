<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Concerns\HasCapellTable;
use Capell\Core\Database\Factories\SecurityWeaknessFindingFactory;
use Capell\Core\Enums\Security\SecurityFindingSource;
use Capell\Core\Enums\Security\SecurityFindingStatus;
use Capell\Core\Enums\Security\SecurityWeaknessSeverity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * One occurrence of a known weakness on this installation, kept across scans
 * so that approvals, dismissals and remediation history survive re-detection.
 *
 * @property int $id
 * @property string $weakness_id
 * @property string $fingerprint
 * @property SecurityFindingSource $source
 * @property SecurityWeaknessSeverity $severity
 * @property SecurityFindingStatus $status
 * @property string $title
 * @property string $summary
 * @property array<string, mixed>|null $evidence
 * @property string|null $composer_name
 * @property bool $remediable
 * @property string|null $recommended_action
 * @property float|null $confidence
 * @property CarbonImmutable $first_detected_at
 * @property CarbonImmutable $last_detected_at
 * @property CarbonImmutable|null $remediated_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $dismissed_at
 * @property int|null $dismissed_by
 * @property string|null $dismissal_note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[UseFactory(SecurityWeaknessFindingFactory::class)]
final class SecurityWeaknessFinding extends Model
{
    use HasCapellTable;

    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    protected $table = 'capell_security_weakness_findings';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @var list<string> */
    protected $fillable = [
        'weakness_id',
        'fingerprint',
        'source',
        'severity',
        'status',
        'title',
        'summary',
        'evidence',
        'composer_name',
        'remediable',
        'recommended_action',
        'confidence',
        'first_detected_at',
        'last_detected_at',
        'remediated_at',
        'resolved_at',
        'dismissed_at',
        'dismissed_by',
        'dismissal_note',
    ];

    /** @return HasMany<SecurityRemediationRun, $this> */
    public function remediationRuns(): HasMany
    {
        return $this->hasMany(SecurityRemediationRun::class, 'finding_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [SecurityFindingStatus::Open->value, SecurityFindingStatus::Remediated->value]);
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'source' => SecurityFindingSource::class,
            'severity' => SecurityWeaknessSeverity::class,
            'status' => SecurityFindingStatus::class,
            'evidence' => 'array',
            'remediable' => 'bool',
            'confidence' => 'float',
            'first_detected_at' => 'immutable_datetime',
            'last_detected_at' => 'immutable_datetime',
            'remediated_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'dismissed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
