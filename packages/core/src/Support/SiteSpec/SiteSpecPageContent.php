<?php

declare(strict_types=1);

namespace Capell\Core\Support\SiteSpec;

use Capell\Core\Actions\SanitizeSiteSpecSectionHtmlAction;
use Capell\Core\Data\SiteSpec\CapellSiteSpecPageData;

/** Turns a SiteSpec page's sections into the HTML stored as the page body. */
final class SiteSpecPageContent
{
    public static function html(CapellSiteSpecPageData $page): string
    {
        return collect($page->sections)->sortBy('order')->map(static function ($section): string {
            $heading = filled($section->title) ? '<h2>' . e($section->title) . '</h2>' : '';

            return $heading . SanitizeSiteSpecSectionHtmlAction::run($section->content);
        })->implode('');
    }
}
