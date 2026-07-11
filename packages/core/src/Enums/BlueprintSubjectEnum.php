<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Capell\Core\Enums\Concerns\HasEnumOptions;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Filament\Support\Contracts\HasLabel;

enum BlueprintSubjectEnum: string implements HasLabel
{
    use HasEnumOptions;

    case Page = 'page';

    case Site = 'site';

    case Theme = 'theme';

    /**
     * Human plural key used in admin labeling.
     */
    public function getKey(): string
    {
        return match ($this) {
            self::Page => 'Pages',
            self::Site => 'Sites',
            self::Theme => 'Themes',
        };
    }

    /**
     * Corresponding model enum.
     */
    public function getModel(): string
    {
        return match ($this) {
            self::Page => Page::class,
            self::Site => Site::class,
            self::Theme => Theme::class,
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
