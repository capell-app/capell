<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Layouts;

use Spatie\LaravelData\Data;

final class LayoutImpactUrlData extends Data
{
    public function __construct(
        public readonly string $locale,
        public readonly string $url,
    ) {}
}
