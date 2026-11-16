<?php

declare(strict_types=1);

namespace Capell\Core\Support\Capabilities;

use Capell\Core\Contracts\Capabilities\LayoutWidgetHost;
use Capell\Core\Data\Capabilities\LayoutWidgetMatchData;
use Capell\Core\Data\Capabilities\LayoutWidgetPlacementData;
use Capell\Core\Data\Capabilities\LayoutWidgetPlacementRequestData;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Override;

/**
 * Used when no installed package provides layout-widget-host.
 */
final class NullLayoutWidgetHost implements LayoutWidgetHost
{
    /**
     * @template TReturn
     *
     * @param  callable(bool): TReturn  $render
     * @return TReturn
     */
    #[Override]
    public function withPreviewWidgets(Page $page, Language $language, array $containers, array $assets, callable $render): mixed
    {
        return $render(false);
    }

    #[Override]
    public function placement(string $reference): ?LayoutWidgetPlacementData
    {
        return null;
    }

    /**
     * @param  list<int>  $siteIds
     * @return list<int>
     */
    #[Override]
    public function pagesPlacing(LayoutWidgetMatchData $match, array $siteIds): array
    {
        return [];
    }

    #[Override]
    public function offersComponent(string $component): bool
    {
        return false;
    }

    #[Override]
    public function placeWidget(Page $page, LayoutWidgetPlacementRequestData $request): bool
    {
        return false;
    }
}
