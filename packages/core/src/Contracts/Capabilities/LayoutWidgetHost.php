<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Capabilities;

use Capell\Core\Data\Capabilities\LayoutWidgetMatchData;
use Capell\Core\Data\Capabilities\LayoutWidgetPlacementData;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;

/**
 * The package that places widgets in page layouts, for packages that preview, read or invalidate
 * those widgets without depending on the layout package. Resolve it through CapabilityRegistry:
 * when no layout package provides it, the null host places nothing and finds nothing.
 */
interface LayoutWidgetHost
{
    /**
     * Run $render with the widgets of unsaved editor state standing in for the saved ones, and
     * forget them once $render returns or throws, so nothing outlives this call. $render receives
     * whether any widget was registered.
     *
     * $containers is the editor's container state keyed by container. $assets is keyed by
     * container, then by the widget's index in that container's normalised widget list; each
     * entry is a list of asset arrays (id, container, workspace_id, pageable_type, pageable_id,
     * asset_type, asset_id, meta, occurrence, order).
     *
     * @template TReturn
     *
     * @param  array<string, mixed>  $containers
     * @param  array<string, mixed>  $assets
     * @param  callable(bool): TReturn  $render
     * @return TReturn
     */
    public function withPreviewWidgets(Page $page, Language $language, array $containers, array $assets, callable $render): mixed;

    /**
     * The widget a rendered page placed, from the opaque reference the public markup carries.
     * Null when the reference is malformed, belongs to another site than the current one, or
     * names no widget the current page renders. Meta is the widget's resolved meta, including
     * what it inherits from its blueprint.
     */
    public function placement(string $reference): ?LayoutWidgetPlacementData;

    /**
     * The pages on these sites whose layouts place a widget matching the given component and meta.
     * The component and meta are the widget's resolved values, including what it inherits from its
     * blueprint; values compare as exact strings.
     *
     * @param  list<int>  $siteIds
     * @return list<int>
     */
    public function pagesPlacing(LayoutWidgetMatchData $match, array $siteIds): array;
}
