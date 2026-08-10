<?php

declare(strict_types=1);

namespace Capell\Core\Data\Publishing;

use Spatie\LaravelData\Data;

final class PublicationReadinessContextData extends Data
{
    public function __construct(
        public readonly int $siteId,
        public readonly int $languageId,
    ) {}
}
