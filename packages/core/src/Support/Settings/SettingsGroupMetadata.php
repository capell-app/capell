<?php

declare(strict_types=1);

namespace Capell\Core\Support\Settings;

use BackedEnum;

final class SettingsGroupMetadata
{
    public function __construct(
        public readonly string $group,
        public readonly string $label,
        public readonly null|string|BackedEnum $icon = null,
        public readonly ?string $navigationGroup = null,
        public readonly int $navigationSort = 90,
        public readonly ?string $packageName = null,
    ) {}

    public function getLabel(): string
    {
        return str_contains($this->label, '::') ? (string) __($this->label) : $this->label;
    }

    public function getNavigationGroup(): ?string
    {
        if ($this->navigationGroup === null) {
            return null;
        }

        return str_contains($this->navigationGroup, '::')
            ? (string) __($this->navigationGroup)
            : $this->navigationGroup;
    }
}
