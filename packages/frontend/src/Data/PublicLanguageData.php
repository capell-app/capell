<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Core\Contracts\PublicRenderData;
use Spatie\LaravelData\Data;

/** The public-safe projection of the record for render data: scalars only, no model, no identifiers. */
final class PublicLanguageData extends Data implements PublicRenderData
{
    public function __construct(
        public string $code,
        public string $name,
        public string $locale,
    ) {}
}
