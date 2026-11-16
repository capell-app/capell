<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Navigation;

use Spatie\LaravelData\Data;

final class SettingsHubEntryData extends Data
{
    public function __construct(
        public readonly string $label,
        public readonly string $url,
        public readonly ?string $description = null,
        public readonly ?bool $configured = null,
    ) {}
}
