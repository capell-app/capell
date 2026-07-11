<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

final class MainContentRenderHookData
{
    public bool $pageContentWidgetRendered = false;

    public bool $pageContentBlockRendered = false;

    public bool $slotRendered = false;

    public function __construct(
        public mixed $layout,
        public mixed $page,
        public mixed $pageSlot = null,
        public array $theme = [],
        public mixed $containerClass = null,
        public mixed $mainClass = null,
        public mixed $mainContainerClass = null,
        public mixed $layoutNeighborLinks = null,
    ) {}
}
