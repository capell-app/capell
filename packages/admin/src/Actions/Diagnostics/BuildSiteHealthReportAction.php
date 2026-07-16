<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Diagnostics;

use Capell\Admin\Contracts\Diagnostics\SiteAwareSiteHealthReportExtender;
use Capell\Admin\Contracts\Diagnostics\SiteHealthReportExtender;
use Capell\Admin\Data\Diagnostics\DiagnosticSectionData;
use Capell\Admin\Data\Diagnostics\SiteHealthReportData;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildSiteHealthReportAction
{
    use AsFake;
    use AsObject;

    public function handle(?int $siteId = null): SiteHealthReportData
    {
        return new SiteHealthReportData(
            optimizerReadiness: BuildOptimizerReadinessDiagnosticsAction::run(),
            environment: BuildProductionEnvironmentDiagnosticsAction::run(),
            extraSections: $this->extraSections($siteId),
        );
    }

    /**
     * @return list<DiagnosticSectionData>
     */
    private function extraSections(?int $siteId): array
    {
        return array_values(collect(app()->tagged(SiteHealthReportExtender::TAG))
            ->filter(fn (mixed $extender): bool => $extender instanceof SiteHealthReportExtender)
            ->flatMap(fn (SiteHealthReportExtender $extender): array => $extender instanceof SiteAwareSiteHealthReportExtender || method_exists($extender, 'sectionsForSite')
                ? $extender->sectionsForSite($siteId)
                : $extender->sections())
            ->values()
            ->all());
    }
}
