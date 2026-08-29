<?php

declare(strict_types=1);

namespace Capell\Core\Data\Extensions;

final readonly class ExtensionOrderDiagnosticData
{
    public function __construct(
        public string $type,
        public string $key,
        public ?string $anchor = null,
        /** @var list<string> */
        public array $cycle = [],
    ) {}
}
