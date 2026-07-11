<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Core\Models\Page;
use Lorisleiva\Actions\Concerns\AsAction;

final class PageHasHeroContentWithoutHeroWidgetAction
{
    use AsAction;

    public function handle(Page $page): bool
    {
        return $this->pageHasHeroContent($page);
    }

    private function pageHasHeroContent(Page $page): bool
    {
        foreach ($page->translations as $translation) {
            $hero = $translation->meta['hero'] ?? null;

            if (is_string($hero) && trim($hero) !== '') {
                return true;
            }
        }

        return false;
    }
}
