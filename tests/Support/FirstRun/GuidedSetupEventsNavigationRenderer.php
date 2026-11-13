<?php

declare(strict_types=1);

namespace Capell\Tests\Support\FirstRun;

use Capell\Admin\Contracts\FirstRun\GuidedSetupPreviewNavigationRenderer;
use Capell\Core\Data\FirstRun\GuidedSetupInputData;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Override;
use RuntimeException;
use Spatie\LaravelData\Data;

/** Exercises the optional theme's real public DTOs without adding a dependency. */
final class GuidedSetupEventsNavigationRenderer implements GuidedSetupPreviewNavigationRenderer
{
    #[Override]
    public function render(string $view, CapellSiteSpecData $spec, GuidedSetupInputData $input): string
    {
        $items = [];
        foreach ($spec->pages as $page) {
            if (in_array($page->slug, $spec->navigations[0]->pageSlugs, true)) {
                $items[] = $this->publicData('Capell\\ThemeEvents\\Data\\EventsChromeLinkData', ['label' => $page->name, 'url' => '#page-' . $page->slug]);
            }
        }

        $section = $this->publicData('Capell\\ThemeEvents\\Data\\EventsNavigationData', [
            'brand' => $spec->site->name, 'homeUrl' => '#page-home', 'items' => $items, 'ctaLabel' => null, 'ctaUrl' => null,
        ]);

        return view()->file($view, ['section' => $section])->render();
    }

    /** @param array<string, mixed> $values */
    private function publicData(string $class, array $values): Data
    {
        throw_unless(is_subclass_of($class, Data::class), RuntimeException::class, 'Missing installed public data: ' . $class);

        return $class::from($values);
    }
}
