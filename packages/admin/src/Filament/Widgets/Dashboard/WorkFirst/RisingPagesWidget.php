<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard\WorkFirst;

use Override;

final class RisingPagesWidget extends PageRankingWidget
{
    #[Override]
    public function isRising(): bool
    {
        return true;
    }

    #[Override]
    protected static function widgetKey(): string
    {
        return 'rising_pages';
    }
}
