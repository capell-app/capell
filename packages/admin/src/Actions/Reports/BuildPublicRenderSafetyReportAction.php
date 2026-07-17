<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Reports;

use Capell\Admin\Contracts\Reports\BuildsReportSnapshot;
use Capell\Admin\Data\Reports\ReportFindingData;
use Capell\Admin\Data\Reports\ReportMetricData;
use Capell\Admin\Data\Reports\ReportSnapshotData;
use Capell\Admin\Enums\Reports\ReportFindingSeverity;
use Capell\Core\Models\PublicRenderContractEvent;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildPublicRenderSafetyReportAction implements BuildsReportSnapshot
{
    use AsFake;
    use AsObject;

    private const int FINDING_LIMIT = 50;

    public function __construct(
        private readonly RuntimeSchemaState $schemaState,
    ) {}

    public function handle(): ReportSnapshotData
    {
        if (! $this->schemaState->hasTable('capell_public_render_contract_events')) {
            return new ReportSnapshotData(
                key: 'core.public_render_safety',
                emptyState: __('capell-admin::reports.empty_state_public_render_safety'),
                findings: [
                    new ReportFindingData(
                        severity: ReportFindingSeverity::Warning,
                        title: __('capell-admin::reports.public_render_safety_table_missing_title'),
                        description: __('capell-admin::reports.public_render_safety_table_missing_description'),
                    ),
                ],
            );
        }

        $total = PublicRenderContractEvent::query()->count();
        $failures = PublicRenderContractEvent::query()->where('result', 'failed')->count();
        $passes = PublicRenderContractEvent::query()->where('result', 'passed')->count();
        $latest = PublicRenderContractEvent::query()->latest('id')->first();

        return new ReportSnapshotData(
            key: 'core.public_render_safety',
            emptyState: __('capell-admin::reports.empty_state_public_render_safety'),
            metrics: [
                new ReportMetricData(
                    label: __('capell-admin::reports.public_render_safety_metric_events'),
                    value: $total,
                    description: __('capell-admin::reports.public_render_safety_metric_events_description'),
                ),
                new ReportMetricData(
                    label: __('capell-admin::reports.public_render_safety_metric_passes'),
                    value: $passes,
                    description: __('capell-admin::reports.public_render_safety_metric_passes_description'),
                ),
                new ReportMetricData(
                    label: __('capell-admin::reports.public_render_safety_metric_failures'),
                    value: $failures,
                    description: __('capell-admin::reports.public_render_safety_metric_failures_description'),
                ),
                new ReportMetricData(
                    label: __('capell-admin::reports.public_render_safety_metric_latest'),
                    value: $latest instanceof PublicRenderContractEvent ? $latest->result : __('capell-admin::reports.public_render_safety_metric_latest_none'),
                    description: __('capell-admin::reports.public_render_safety_metric_latest_description'),
                ),
            ],
            findings: $this->findings(),
        );
    }

    /** @return list<ReportFindingData> */
    private function findings(): array
    {
        return array_values(PublicRenderContractEvent::query()
            ->where('result', 'failed')
            ->latest('id')
            ->limit(self::FINDING_LIMIT)
            ->get()
            ->map(fn (PublicRenderContractEvent $event): ReportFindingData => new ReportFindingData(
                severity: ReportFindingSeverity::Critical,
                title: __('capell-admin::reports.public_render_safety_failure_title'),
                description: __('capell-admin::reports.public_render_safety_failure_description', [
                    'reason' => $event->reason ?? __('capell-admin::reports.public_render_safety_unknown_reason'),
                    'matched' => $event->matched_marker ?? __('capell-admin::reports.public_render_safety_unknown_marker'),
                ]),
                recordLabel: $this->recordLabel($event),
            ))
            ->values()
            ->all());
    }

    private function recordLabel(PublicRenderContractEvent $event): string
    {
        $parts = array_values(array_filter([
            $event->package_name,
            $event->page_id !== null ? 'page #' . $event->page_id : null,
            $event->layout_id !== null ? 'layout #' . $event->layout_id : null,
            $event->theme_id !== null ? 'theme #' . $event->theme_id : null,
        ]));

        return $parts === []
            ? __('capell-admin::reports.public_render_safety_unattributed')
            : implode(' / ', $parts);
    }
}
