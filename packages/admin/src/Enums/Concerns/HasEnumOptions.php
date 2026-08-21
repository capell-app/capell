<?php

declare(strict_types=1);

namespace Capell\Admin\Enums\Concerns;

use Filament\Support\Contracts\HasLabel;

/** @mixin HasLabel */
trait HasEnumOptions
{
    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        /** @var array<string, array<string, string>> $optionsByLocale */
        static $optionsByLocale = [];

        $locale = app()->getLocale();

        return $optionsByLocale[$locale] ??= collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->getLabel()])
            ->all();
    }
}
