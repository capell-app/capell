<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Data;

use Capell\Core\ThemeStudio\Contracts\ThemeSection;

/**
 * A type-agnostic theme section.
 *
 * Carries an arbitrary, ordered payload for any registered section renderer,
 * resolved purely by {@see self::key()}. Section views read their payload as
 * object properties off `$section` (e.g. `$section->items`, `$section->metrics`);
 * the {@see self::__get()}/{@see self::__isset()} pair exposes the carried array
 * through that property syntax so a renderer needs no bespoke data class.
 *
 * When the theme lacks a renderer for {@see self::key()}, the renderer degrades
 * to {@see self::fallbackKey()} (a generic listing by default) instead of
 * throwing, so signature sections stay safe to seed across the fleet.
 *
 * @deprecated Part of the section-rendering pipeline being replaced by
 * x-capell::layout + layout-builder widget rendering.
 */
final class GenericSectionData implements ThemeSection
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        private readonly string $type,
        private readonly array $data = [],
        private readonly ?string $fallback = 'content-listing',
    ) {}

    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->data);
    }

    public function key(): string
    {
        return $this->type;
    }

    public function fallbackKey(): ?string
    {
        return $this->fallback;
    }

    /**
     * @return array<string, mixed>
     */
    public function toViewData(): array
    {
        return [
            ...$this->data,
            'section' => $this,
        ];
    }
}
