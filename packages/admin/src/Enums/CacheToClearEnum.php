<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Filament\Support\Contracts\HasLabel;

enum CacheToClearEnum: string implements HasLabel
{
    case Page = 'page';

    case Config = 'config';

    case Views = 'views';

    public function getLabel(): string
    {
        return match ($this) {
            self::Page => 'HTML page cache',
            self::Config => 'Config cache',
            self::Views => 'Views cache',
        };
    }
}
