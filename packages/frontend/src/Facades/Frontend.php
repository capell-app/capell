<?php

declare(strict_types=1);

namespace Capell\Frontend\Facades;

use Capell\Frontend\Contracts\FrontendContextReader;
use Illuminate\Support\Facades\Facade;

/**
 * @mixin FrontendContextReader
 */
final class Frontend extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FrontendContextReader::class;
    }
}
