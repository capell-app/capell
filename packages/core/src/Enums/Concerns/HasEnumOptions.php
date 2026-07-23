<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Concerns;

use Filament\Support\Contracts\HasLabel;

/**
 * @mixin HasLabel
 */
trait HasEnumOptions
{
    /**
     * Get all options as [value => label] pairs for select fields.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        // Labels are translated, so the memo must be keyed by locale. A single
        // shared memo would freeze option labels to whichever locale happened to
        // be active the first time this ran, which persists for the life of the
        // process under Laravel Octane.
        /** @var array<string, array<string, string>> $optionsByLocale */
        static $optionsByLocale = [];

        $locale = app()->getLocale();

        return $optionsByLocale[$locale] ??= collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->getLabel()])
            ->all();
    }
}
