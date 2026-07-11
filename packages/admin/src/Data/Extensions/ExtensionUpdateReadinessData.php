<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Extensions;

use Spatie\LaravelData\Data;

final class ExtensionUpdateReadinessData extends Data
{
    public function __construct(
        public readonly string $packageName,
        public readonly string $state,
        public readonly ?string $currentVersion = null,
        public readonly ?string $latestVersion = null,
        public readonly ?string $blocker = null,
    ) {}
}
