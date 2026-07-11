<?php

declare(strict_types=1);

namespace Capell\Frontend\Facades;

use Capell\Frontend\Support\CapellFrontendContext;
use Illuminate\Support\Facades\Facade;

/**
 * @mixin CapellFrontendContext
 */
final class Frontend extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CapellFrontendContext::class;
    }
}
