<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Capell\Core\Enums\Concerns\HasEnumOptions;
use Filament\Support\Contracts\HasLabel;

enum BlueprintSubjectEnum: string implements HasLabel
{
    use HasEnumOptions;

    case Page = 'page';

    case Site = 'site';

    case Theme = 'theme';

    /**
     * Stable subject key used by the registry.
     */
    public function getKey(): string
    {
        return match ($this) {
            self::Page, self::Site, self::Theme => $this->value,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Page => 'Page',
            self::Site => 'Site',
            self::Theme => 'Theme',
        };
    }
}
