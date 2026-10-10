<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Dashboard\Fixtures;

use Filament\Widgets\Widget;
use Override;

class HiddenCompositionWidget extends Widget
{
    #[Override]
    public static function canView(): bool
    {
        return false;
    }
}
