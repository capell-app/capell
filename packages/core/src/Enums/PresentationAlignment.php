<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Capell\Core\Enums\Concerns\HasEnumOptions;
use Filament\Support\Contracts\HasLabel;

enum PresentationAlignment: string implements HasLabel
{
    use HasEnumOptions;

    case Stretch = 'stretch';
    case Left = 'left';
    case Center = 'center';
    case Right = 'right';

    public function getLabel(): string
    {
        return (string) __('capell::generic.presentation_alignment_' . $this->value);
    }
}
