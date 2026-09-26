<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Reporting;

use Capell\Core\Enums\Reporting\IncidentStatus;
use Capell\Core\Models\ReportingIncident;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Delete a bounded batch of resolved incidents past retention. Unresolved and
 * acknowledged incidents are always retained.
 */
final readonly class PruneReportingIncidentsAction
{
    use AsFake;
    use AsObject;

    public function handle(int $retentionDays = 30, int $limit = 1000): int
    {
        throw_if($retentionDays < 1 || $retentionDays > 3650 || $limit < 1 || $limit > 10000, InvalidArgumentException::class, 'Reporting retention or batch size is invalid.');
        $query = ReportingIncident::query()->where('status', IncidentStatus::Resolved)->where('resolved_at', '<=', now()->subDays($retentionDays));
        $ids = (clone $query)->oldest('resolved_at')->orderBy('fingerprint')->limit($limit)->pluck('fingerprint');

        return $query->whereIn('fingerprint', $ids)->delete();
    }
}
