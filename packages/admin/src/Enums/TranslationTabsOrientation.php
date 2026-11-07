<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

/**
 * How translation tabs sit next to the fields they switch between. Every
 * Capell translation surface takes one of these so the layout is a setting,
 * not a fork of the component.
 */
enum TranslationTabsOrientation: string
{
    case Horizontal = 'horizontal';

    case Vertical = 'vertical';

    /** The site-wide default from `capell-admin.translation_tabs_orientation`; unknown values fall back to horizontal. */
    public static function configured(): self
    {
        $value = config('capell-admin.translation_tabs_orientation');

        return is_string($value) ? (self::tryFrom($value) ?? self::Horizontal) : self::Horizontal;
    }

    public function isVertical(): bool
    {
        return $this === self::Vertical;
    }
}
