<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Sites;

use Capell\Core\Data\DeletionImpactData;
use Illuminate\Support\Number;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static string run(DeletionImpactData $impact)
 */
final class BuildSiteDeletionImpactDescriptionAction
{
    use AsObject;

    public function handle(DeletionImpactData $impact): string
    {
        if ($impact->total() === 0) {
            return __('capell-admin::generic.site_delete_impact_none');
        }

        return __('capell-admin::generic.site_delete_impact_description', [
            'pages' => $this->label('site_delete_pages_affected', $impact->pages),
            'domains' => $this->label('site_delete_domains_affected', $impact->siteDomains),
            'layouts' => $this->label('site_delete_layouts_affected', $impact->layouts),
            'page_urls' => $this->label('site_delete_page_urls_affected', $impact->pageUrls),
            'translations' => $this->label('site_delete_translations_affected', $impact->translations),
        ]);
    }

    private function label(string $key, int $count): string
    {
        return trans_choice('capell-admin::generic.' . $key, $count, [
            'count' => Number::format($count),
        ]);
    }
}
