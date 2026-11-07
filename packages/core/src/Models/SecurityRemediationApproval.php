<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Concerns\HasCapellTable;
use Capell\Core\Database\Factories\SecurityRemediationApprovalFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * An operator's consent to fix one finding with specific evidence.
 * One row per weakness id; revoking keeps the row as history.
 *
 * @property int $id
 * @property string $weakness_id
 * @property string|null $approved_for_scan_id
 * @property string|null $approved_fingerprint
 * @property string|null $evidence_hash
 * @property CarbonImmutable|null $superseded_at
 * @property string|null $remediation_class
 * @property int|null $approved_by
 * @property CarbonImmutable $approved_at
 * @property int|null $revoked_by
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[UseFactory(SecurityRemediationApprovalFactory::class)]
final class SecurityRemediationApproval extends Model
{
    use HasCapellTable;

    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    protected $table = 'capell_security_remediation_approvals';

    /** @var list<string> */
    protected $fillable = [
        'weakness_id',
        'approved_for_scan_id',
        'approved_fingerprint',
        'evidence_hash',
        'superseded_at',
        'remediation_class',
        'approved_by',
        'approved_at',
        'revoked_by',
        'revoked_at',
        'note',
    ];

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->superseded_at === null && $this->approved_for_scan_id !== null && $this->approved_fingerprint !== null && $this->evidence_hash !== null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->whereNull('superseded_at')->whereNotNull('approved_for_scan_id')->whereNotNull('approved_fingerprint')->whereNotNull('evidence_hash');
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'approved_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
