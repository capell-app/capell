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
 * An operator's standing consent for Capell to fix one kind of weakness on
 * its own. One row per weakness id; revoking keeps the row as history.
 *
 * @property int $id
 * @property string $weakness_id
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
        'approved_by',
        'approved_at',
        'revoked_by',
        'revoked_at',
        'note',
    ];

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'approved_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
