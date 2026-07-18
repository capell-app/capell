<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics\Checks;

use Capell\Core\Actions\FindPageUrlsMissingSiteDomainsAction;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Models\PageUrl;
use Illuminate\Support\Facades\Schema;

final class PageUrlSiteDomainsCheck extends AbstractDoctorCheck
{
    protected function id(): string
    {
        return 'core.page-urls.site-domains';
    }

    protected function severity(): DoctorCheckSeverity
    {
        return DoctorCheckSeverity::Critical;
    }

    protected function run(bool $installSummary): DoctorCheckResultData
    {
        if (! Schema::hasTable('page_urls') || ! Schema::hasTable('site_domains') || ! Schema::hasTable('sites') || ! Schema::hasTable('languages')) {
            return new DoctorCheckResultData('Page URLs have site domains', true, 'Skipped until page URL, site domain, site, and language tables exist.');
        }

        $missing = FindPageUrlsMissingSiteDomainsAction::run();

        if ($missing->isEmpty()) {
            return new DoctorCheckResultData('Page URLs have site domains', true, 'Every page URL has a matching active site domain.');
        }

        $examples = $missing->take(5)->map(fn (PageUrl $pageUrl): string => sprintf('#%d site:%d language:%d', $pageUrl->getKey(), $pageUrl->site_id, $pageUrl->language_id))->implode(', ');

        return new DoctorCheckResultData(
            'Page URLs have site domains',
            false,
            sprintf('%d page URL(s) are missing matching active site domains. Examples: %s.', $missing->count(), $examples),
            'Run php artisan capell:doctor --repair-page-url-domains or rerun the relevant site/demo installer.',
        );
    }
}
