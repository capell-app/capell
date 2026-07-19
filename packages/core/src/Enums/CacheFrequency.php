<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Filament\Support\Contracts\HasLabel;

enum CacheFrequency: string implements HasLabel
{
    case Always = 'always';

    public function getLabel(): string
    {
        return (string) __('capell::generic.always');
    }
}
