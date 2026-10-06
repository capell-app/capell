<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Extensions;

use Capell\Admin\Filament\Pages\SiteHealthPage;
use Override;

final class ExtensionHealthStatusFilamentWidget extends ExtensionHealthFilamentWidget
{
    protected static bool $isDiscovered = false;

    #[Override]
    public static function canView(): bool
    {
        // The health page shows healthy states too; dashboard visibility only shows alerts.
        return auth()->check() && SiteHealthPage::canAccess();
    }
}
