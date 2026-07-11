<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Frontend\Data\FrontendRenderContextData;

interface PublicWidgetInteractionLocatorBuilder
{
    /**
     * Build request-safe locators before public Blade rendering begins.
     *
     * @return array<string, string> Widget instance ID to public locator URL.
     */
    public function build(FrontendRenderContextData $context): array;
}
