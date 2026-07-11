<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Diagnostics;

use Spatie\LaravelData\Data;

final class SiteHealthReportData extends Data
{
    /**
     * @param  list<DiagnosticCheckData>  $optimizerReadiness
     * @param  list<DiagnosticCheckData>  $environment
     * @param  list<DiagnosticSectionData>  $extraSections
     */
    public function __construct(
        public readonly array $optimizerReadiness,
        public readonly array $environment,
        public readonly array $extraSections = [],
    ) {}

    public function hasRedChecks(): bool
    {
        return collect($this->sections())
            ->flatMap(fn (DiagnosticSectionData $section): array => $section->checks)
            ->contains(fn (DiagnosticCheckData $check): bool => $check->isRed());
    }

    /**
     * @return list<DiagnosticSectionData>
     */
    public function sections(): array
    {
        return [
            new DiagnosticSectionData(
                label: (string) __('capell-admin::generic.site_health_optimizer'),
                checks: $this->optimizerReadiness,
            ),
            new DiagnosticSectionData(
                label: (string) __('capell-admin::generic.site_health_environment'),
                checks: $this->environment,
            ),
            ...$this->extraSections,
        ];
    }
}
