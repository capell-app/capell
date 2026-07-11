<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Themes;

use Spatie\LaravelData\Data;

final class ThemeCompatibilityData extends Data
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public bool $compatible,
        public array $warnings,
    ) {}

    /**
     * @param  array<string, mixed>  $admin
     */
    public static function fromAdmin(array $admin): self
    {
        $compatibility = is_array($admin['compatibility'] ?? null) ? $admin['compatibility'] : [];
        $warnings = [];

        foreach (($compatibility['warnings'] ?? []) as $warning) {
            if (! is_string($warning)) {
                continue;
            }

            if (trim($warning) === '') {
                continue;
            }

            $warnings[] = trim($warning);
        }

        return new self(
            compatible: $warnings === [],
            warnings: $warnings,
        );
    }
}
