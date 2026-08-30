<?php

declare(strict_types=1);

namespace Capell\Core\Data\EditorImpact;

use Spatie\LaravelData\Data;

final class EditorImpactUrlData extends Data
{
    public function __construct(
        public readonly string $locale,
        public readonly string $url,
    ) {}
}
