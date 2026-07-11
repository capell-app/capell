<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Extensions;

use Spatie\LaravelData\Data;

final class ExtensionRuntimeCompatibilityData extends Data
{
    /** @param list<string> $requirements */
    public function __construct(
        public readonly string $packageName,
        public readonly string $state,
        public readonly array $requirements = [],
        public readonly ?string $message = null,
    ) {}
}
