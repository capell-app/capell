<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Extensions;

use Spatie\LaravelData\Data;

final class ExtensionQuickActionData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?string $url = null,
        public readonly ?string $permission = null,
        public readonly bool $destructive = false,
    ) {}
}
