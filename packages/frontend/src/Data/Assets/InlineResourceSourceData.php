<?php

declare(strict_types=1);

namespace Capell\Frontend\Data\Assets;

use Capell\Frontend\Contracts\FrontendResourceSourceData;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

final class InlineResourceSourceData extends Data implements FrontendResourceSourceData
{
    public function __construct(public readonly string $content)
    {
        throw_if($content === '', InvalidArgumentException::class, 'Inline resource content cannot be empty.');
    }
}
