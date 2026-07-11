<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Filament\Support\Contracts\HasLabel;

enum LayoutEnum: string implements HasLabel
{
    case Default = 'default';

    case Home = 'home';

    case Results = 'results';

    case System = 'system';

    public function getLabel(): string
    {
        return match ($this) {
            self::Default => __('capell::layout.default'),
            self::Home => __('capell::layout.home'),
            self::Results => __('capell::layout.results'),
            self::System => __('capell::layout.system'),
        };
    }

    public function getGroup(): LayoutGroupEnum
    {
        return match ($this) {
            self::Results, self::System => LayoutGroupEnum::System,
            default => LayoutGroupEnum::Default
        };
    }
}
