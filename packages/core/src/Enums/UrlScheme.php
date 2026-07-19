<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Filament\Support\Contracts\HasLabel;

enum UrlScheme: string implements HasLabel
{
    case Http = 'http';
    case Https = 'https';

    public function getLabel(): string
    {
        return (string) __('capell::generic.' . $this->value);
    }
}
