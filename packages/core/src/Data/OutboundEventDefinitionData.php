<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Spatie\LaravelData\Data;

final class OutboundEventDefinitionData extends Data
{
    /**
     * @param  class-string<Data>  $payloadClass
     */
    public function __construct(
        public readonly string $name,
        public readonly int $version,
        public readonly string $payloadClass,
        public readonly string $description,
        public readonly string $ownerPackage,
    ) {}
}
