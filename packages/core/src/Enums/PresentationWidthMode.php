<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Capell\Core\Enums\Concerns\HasEnumOptions;
use Filament\Support\Contracts\HasLabel;

enum PresentationWidthMode: string implements HasLabel
{
    use HasEnumOptions;

    case Inherit = 'inherit';
    case Full = 'full';
    case Container = 'container';
    case Custom = 'custom';

    public function getLabel(): string
    {
        return (string) __('capell::generic.presentation_width_' . $this->value);
    }
}
