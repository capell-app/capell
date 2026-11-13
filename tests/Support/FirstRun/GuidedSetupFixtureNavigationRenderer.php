<?php

declare(strict_types=1);

namespace Capell\Tests\Support\FirstRun;

use Capell\Admin\Contracts\FirstRun\GuidedSetupPreviewNavigationRenderer;
use Capell\Core\Data\FirstRun\GuidedSetupInputData;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Override;

final class GuidedSetupFixtureNavigationRenderer implements GuidedSetupPreviewNavigationRenderer
{
    #[Override]
    public function render(string $view, CapellSiteSpecData $spec, GuidedSetupInputData $input): string
    {
        $items = [];
        foreach ($spec->pages as $page) {
            if (in_array($page->slug, $spec->navigations[0]->pageSlugs, true)) {
                $items[] = ['label' => $page->name, 'url' => '#page-' . $page->slug];
            }
        }

        return view()->file($view, ['name' => $spec->site->name, 'items' => $items])->render();
    }
}
