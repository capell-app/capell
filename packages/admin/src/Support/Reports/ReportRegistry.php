<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Reports;

use Capell\Admin\Data\Reports\ReportDefinitionData;

final class ReportRegistry
{
    /** @var array<string, ReportDefinitionData> */
    private array $reports = [];

    public function register(ReportDefinitionData $report): void
    {
        if ($report->key === '') {
            return;
        }

        $this->reports[$report->key] = $report;
    }

    public function get(string $key): ?ReportDefinitionData
    {
        return $this->reports[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->reports[$key]);
    }

    /** @return array<string, ReportDefinitionData> */
    public function all(): array
    {
        return collect($this->reports)
            ->sortBy([
                fn (ReportDefinitionData $report): string => $report->package,
                fn (ReportDefinitionData $report): string => $report->category,
                fn (ReportDefinitionData $report): int => $report->navigationSort,
                fn (ReportDefinitionData $report): string => $report->resolvedLabel(),
            ])
            ->all();
    }

    /** @return list<class-string> */
    public function pageClasses(): array
    {
        return array_values(collect($this->all())
            ->pluck('pageClass')
            ->unique()
            ->values()
            ->all());
    }

    public function clear(): void
    {
        $this->reports = [];
    }
}
