<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Contracts\Capabilities\LayoutWidgetHost;
use Capell\Core\Data\Capabilities\LayoutWidgetMatchData;
use Capell\Core\Data\Capabilities\LayoutWidgetPlacementData;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Override;

final class CapabilityLayoutWidgetHostFixture implements LayoutWidgetHost
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
        return $render($containers !== []);
    }

    #[Override]
    public function placement(string $reference): ?LayoutWidgetPlacementData
    {
        return $reference === 'known'
            ? new LayoutWidgetPlacementData('main', 'contact-form', 1, ['form_handle' => ' contact '])
            : null;
    }

    /**
     * @param  list<int>  $siteIds
     * @return list<int>
     */
    #[Override]
    public function pagesPlacing(LayoutWidgetMatchData $match, array $siteIds): array
    {
        return in_array('contact', $match->values, true) ? [11, 12] : [];
    }
}
