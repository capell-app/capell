<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\EventSourcing\Enums\PageWorkflowStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Read-model row holding a page's current editorial workflow status, rebuilt
 * from the event stream by PageProjector. One row per page (keyed by uuid).
 *
 * @property string $page_uuid
 * @property PageWorkflowStatus $status
 * @property int|null $approver_id
 * @property string|null $requested_changes_note
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $scheduled_for
 * @property int $aggregate_version
 */
class PageWorkflowState extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    protected $fillable = [
        'page_uuid',
        'status',
        'approver_id',
        'requested_changes_note',
        'submitted_at',
        'scheduled_for',
        'aggregate_version',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'status' => PageWorkflowStatus::class,
            'submitted_at' => 'immutable_datetime',
            'scheduled_for' => 'immutable_datetime',
            'aggregate_version' => 'integer',
        ];
    }
}
