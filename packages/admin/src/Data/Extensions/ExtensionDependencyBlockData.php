<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Extensions;

use Spatie\LaravelData\Data;

final class ExtensionDependencyBlockData extends Data
{
    public function __construct(
        public readonly string $packageName,
        public readonly string $blockedPackageName,
        public readonly string $operation,
        public readonly string $reason,
    ) {}
}
