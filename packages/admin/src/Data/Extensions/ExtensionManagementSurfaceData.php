<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Extensions;

use BackedEnum;
use Spatie\LaravelData\Data;

final class ExtensionManagementSurfaceData extends Data
{
    public function __construct(
        public readonly string $packageName,
        public readonly string $label,
        public readonly string $type,
        public readonly null|string|BackedEnum $icon = null,
        public readonly ?string $settingsGroup = null,
    ) {}

    public static function settings(
        string $packageName,
        string $label,
        string $settingsGroup,
        null|string|BackedEnum $icon = null,
    ): self {
        return new self(
            packageName: $packageName,
            label: $label,
            type: 'settings',
            icon: $icon,
            settingsGroup: $settingsGroup,
        );
    }

    /**
     * @return array{
     *     packageName: string,
     *     label: string,
     *     type: string,
     *     icon: null|string|BackedEnum,
     *     settingsGroup: ?string
     * }
     */
    public function toRecord(): array
    {
        return [
            'packageName' => $this->packageName,
            'label' => (string) __($this->label),
            'type' => $this->type,
            'icon' => $this->icon,
            'settingsGroup' => $this->settingsGroup,
        ];
    }
}
