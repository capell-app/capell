<?php

declare(strict_types=1);

namespace Capell\Core\Data\Security;

use Spatie\LaravelData\Data;

final class SecurityInventoryPackageData extends Data
{
    public function __construct(
        public readonly string $composerName,
        public readonly ?string $version,
        public readonly ?string $slug = null,
        public readonly ?string $autoUpdatePolicy = null,
        public readonly bool $trusted = false,
        public readonly bool $enabled = false,
    ) {}
}
