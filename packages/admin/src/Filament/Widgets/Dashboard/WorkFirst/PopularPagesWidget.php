<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard\WorkFirst;

use Override;

final class PopularPagesWidget extends PageRankingWidget
{
    #[Override]
    public function isRising(): bool
    {
        return false;
    }

    #[Override]
    protected static function widgetKey(): string
    {
        return 'popular_pages';
    }
}
