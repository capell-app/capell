<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Frontend\Data\RenderHookContext;

interface RenderHookExtensionInterface
{
    public function render(RenderHookContext $context): string;
}
