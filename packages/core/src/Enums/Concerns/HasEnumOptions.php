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
        static $options = null;
        if ($options === null) {
            $options = collect(self::cases())
                ->mapWithKeys(fn (self $case): array => [$case->value => $case->getLabel()])
                ->all();
        }

        return $options;
    }
}
