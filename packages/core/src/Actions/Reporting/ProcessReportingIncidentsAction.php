<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Reporting;

use Capell\Core\Data\Reporting\RedactedSignalData;
use Capell\Core\Enums\Reporting\IncidentStatus;
use Capell\Core\Models\ReportingIncident;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Retry pending deliveries and evaluate backup escalation in bounded rotating batches.
 * Schedule from the host; storage failures propagate so the scheduler can report failure.
 */
final readonly class ProcessReportingIncidentsAction
{
    use AsFake;
    use AsObject;

    public function __construct(private DispatchSignalAction $dispatch) {}

    /** @return array<string, int> */
    public function handle(int $limit = 100): array
    {
        throw_if($limit < 1 || $limit > 1000, InvalidArgumentException::class, 'Reporting batch size must be between 1 and 1000.');
        $results = [];
        $incidents = ReportingIncident::query()->whereIn('status', [IncidentStatus::Open, IncidentStatus::Escalated])
            ->orderByRaw('CASE WHEN checked_at IS NULL THEN 0 ELSE 1 END')
            ->oldest('checked_at')->orderBy('fingerprint')->limit($limit)->get();
        foreach ($incidents as $incident) {
            // Rotate even disabled or corrupt records so a bounded batch cannot starve later incidents.
            $incident->update(['checked_at' => now()]);
            try {
                $status = $this->dispatch->handle(RedactedSignalData::fromStoredArray($incident->signal))->status->value;
            } catch (Throwable) {
                $status = 'failed';
            }

            $results[$status] = ($results[$status] ?? 0) + 1;
        }

        return $results;
    }
}
