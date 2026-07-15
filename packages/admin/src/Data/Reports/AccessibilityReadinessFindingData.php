<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Reports;

use Capell\Admin\Enums\Reports\ReportFindingSeverity;
use Spatie\LaravelData\Data;

final class AccessibilityReadinessFindingData extends Data
{
    /** @param array<string, mixed> $evidence */
    public function __construct(
        public readonly string $id,
        public readonly ReportFindingSeverity $severity,
        public readonly string $title,
        public readonly string $description,
        public readonly string $recordLabel,
        public readonly string $remediation,
        public readonly array $evidence,
        public readonly ?string $url = null,
    ) {}

    public function toReportFinding(): ReportFindingData
    {
        return new ReportFindingData(
            severity: $this->severity,
            title: $this->title,
            description: $this->description,
            recordLabel: $this->recordLabel,
            actionLabel: $this->url !== null ? (string) __('capell-admin::reports.accessibility_action_edit') : null,
            url: $this->url,
            id: $this->id,
            remediation: $this->remediation,
            evidence: $this->evidence,
        );
    }
}
