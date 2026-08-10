<?php

declare(strict_types=1);

namespace Capell\Core\Data\Publishing;

use Spatie\LaravelData\Data;

final class PublicationReadinessCheckData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly bool $blocking = true,
    ) {}
}
