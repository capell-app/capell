<?php

declare(strict_types=1);

namespace Capell\Admin\Data;

use BackedEnum;
use Capell\Admin\Enums\MarketingStudioSectionEnum;
use Closure;
use Spatie\LaravelData\Data;

final class MarketingStudioActionData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string|Closure $label,
        public readonly string|Closure $url,
        public readonly MarketingStudioSectionEnum $section,
        public readonly string|BackedEnum|null $icon = null,
        public readonly int $sort = 100,
        public readonly string|Closure|null $description = null,
        public readonly int|string|Closure|null $badge = null,
        public readonly string|Closure|null $badgeColor = null,
        public readonly bool|Closure $visible = true,
    ) {}

    public function isVisible(): bool
    {
        return (bool) value($this->visible);
    }

    public function resolvedLabel(): string
    {
        return (string) value($this->label);
    }

    public function resolvedUrl(): string
    {
        return (string) value($this->url);
    }

    public function resolvedDescription(): ?string
    {
        $description = value($this->description);

        return is_string($description) && $description !== '' ? $description : null;
    }

    public function resolvedBadge(): int|string|null
    {
        $badge = value($this->badge);

        return is_int($badge) || is_string($badge) ? $badge : null;
    }

    public function resolvedBadgeColor(): ?string
    {
        $badgeColor = value($this->badgeColor);

        return is_string($badgeColor) && $badgeColor !== '' ? $badgeColor : null;
    }
}
