<?php

declare(strict_types=1);

namespace Capell\Admin\Data;

use BackedEnum;
use Capell\Admin\Enums\NavigationGroupPositionEnum;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

final class NavigationGroupData extends Data
{
    public function __construct(
        public readonly string $label,
        public readonly null|string|BackedEnum $icon = null,
        public readonly NavigationGroupPositionEnum $position = NavigationGroupPositionEnum::End,
        public readonly ?string $relativeTo = null,
        public readonly bool $collapsed = true,
    ) {
        if ($this->requiresRelativeGroup() && $this->relativeTo === null) {
            throw new InvalidArgumentException(
                sprintf('Navigation group position [%s] requires a relative group label.', $this->position->value),
            );
        }
    }

    public function merge(NavigationGroupData $navigationGroup): self
    {
        return new self(
            label: $this->label,
            icon: $navigationGroup->icon ?? $this->icon,
            position: $navigationGroup->position,
            relativeTo: $navigationGroup->relativeTo,
            collapsed: $this->collapsed && $navigationGroup->collapsed,
        );
    }

    private function requiresRelativeGroup(): bool
    {
        return in_array($this->position, [
            NavigationGroupPositionEnum::Before,
            NavigationGroupPositionEnum::After,
        ], true);
    }
}
