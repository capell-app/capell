<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Reporting;

use Capell\Core\Data\Reporting\ReportingHealthData;
use Capell\Core\Enums\Reporting\IncidentStatus;
use Capell\Core\Models\ReportingIncident;
use Illuminate\Contracts\Config\Repository;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Read aggregate health for the opt-in endpoint without exposing incident details.
 * Missing storage returns unavailable.
 */
final readonly class GetReportingHealthAction
{
    use AsFake;
    use AsObject;

    public function __construct(private Repository $configuration) {}

    public function handle(): ReportingHealthData
    {
        try {
            if ($this->configuration->get('capell-reporting.enabled') !== true || $this->configuration->get('capell-reporting.health.enabled') !== true) {
                return new ReportingHealthData('disabled');
            }

            $incidents = ReportingIncident::query()->where('health', true)->where('status', '!=', IncidentStatus::Resolved);
            $unresolved = (clone $incidents)->count();

            return new ReportingHealthData(
                $unresolved > 0 ? 'degraded' : 'ok',
                $unresolved,
                (clone $incidents)->where('status', IncidentStatus::Acknowledged)->count(),
                (clone $incidents)->where('status', IncidentStatus::Escalated)->count(),
                (clone $incidents)->where('delivery_failed', true)->count(),
            );
        } catch (Throwable) {
            return new ReportingHealthData('unavailable');
        }
    }
}
