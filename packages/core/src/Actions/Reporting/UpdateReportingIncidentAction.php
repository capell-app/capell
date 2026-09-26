<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Reporting;

use Capell\Core\Enums\Reporting\IncidentStatus;
use Capell\Core\Models\ReportingIncident;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Acknowledge or resolve an incident for an assigned alias. The host must authenticate
 * the operator before invoking this action; invalid transitions return false.
 */
final readonly class UpdateReportingIncidentAction
{
    use AsFake;
    use AsObject;

    public function handle(string $id, IncidentStatus $status, string $operator): bool
    {
        if (! in_array($status, [IncidentStatus::Acknowledged, IncidentStatus::Resolved], true)) {
            return false;
        }

        $attributes = $status === IncidentStatus::Acknowledged
            ? ['acknowledged_at' => now(), 'acknowledged_by' => $operator]
            : ['resolved_at' => now()];

        return ReportingIncident::query()->whereKey($id)
            ->whereIn('status', $status === IncidentStatus::Acknowledged ? [IncidentStatus::Open, IncidentStatus::Escalated] : [IncidentStatus::Open, IncidentStatus::Escalated, IncidentStatus::Acknowledged])
            ->where(fn (Builder $query): Builder => $query->where('owner', $operator)->orWhere('backup', $operator))
            ->update(['status' => $status, ...$attributes]) === 1;
    }
}
