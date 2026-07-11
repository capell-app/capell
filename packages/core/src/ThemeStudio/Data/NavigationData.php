<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Data;

use Capell\Core\ThemeStudio\Contracts\ThemeSection;
use OutOfBoundsException;
use Spatie\LaravelData\Data;

/**
 * @deprecated Part of the section-rendering pipeline being replaced by
 * x-capell::layout + layout-builder widget rendering.
 */
class NavigationData extends Data implements ThemeSection
{
    /**
     * @param  array<int, array{label: string, url: string}>  $items
     */
    public function __construct(
        public string $brandName,
        public array $items = [],
        public ?string $ctaLabel = null,
        public ?string $ctaUrl = null,
    ) {}

    /**
     * Expose {@see $brandName} under the `brand` alias so theme navigation
     * views reading `data_get($section, 'brand')` resolve the seeded brand
     * instead of falling back to the translation default. Declared as a magic
     * accessor (rather than a real/`#[Computed]` property) so demo seeds that
     * also pass a `brand` input key hydrate without a computed-value clash.
     */
    public function __isset(string $name): bool
    {
        return $name === 'brand';
    }

    public function __get(string $name): mixed
    {
        if ($name === 'brand') {
            return $this->brandName;
        }

        throw new OutOfBoundsException(sprintf('Undefined property %s::$%s', static::class, $name));
    }

    public function key(): string
    {
        return 'navigation';
    }

    public function fallbackKey(): ?string
    {
        return null;
    }

    public function toViewData(): array
    {
        return ['section' => $this];
    }
}
