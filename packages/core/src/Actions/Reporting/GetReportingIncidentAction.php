<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Reporting;

use Capell\Core\Data\Reporting\ReportingIncidentData;
use Capell\Core\Models\ReportingIncident;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Read a private operational snapshot by fingerprint; unknown IDs return null. Storage
 * failures propagate to the caller.
 */
final readonly class GetReportingIncidentAction
{
    use AsFake;
    use AsObject;

    public function handle(string $id): ?ReportingIncidentData
    {
        $incident = ReportingIncident::query()->find($id);

        return $incident === null ? null : ReportingIncidentData::fromIncident($incident);
    }
}
