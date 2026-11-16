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
        // Labels are read from the translator on every call. The translator already keeps
        // each loaded group, and a process-wide memo would keep serving labels from a
        // translator that has since been replaced or given new lines.
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->getLabel()])
            ->all();
    }
}
