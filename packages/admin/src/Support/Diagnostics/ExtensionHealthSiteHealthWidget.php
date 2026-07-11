<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Diagnostics;

use Capell\Admin\Contracts\Diagnostics\SiteHealthWidget;
use Capell\Admin\Filament\Widgets\Extensions\ExtensionHealthFilamentWidget;

final class ExtensionHealthSiteHealthWidget implements SiteHealthWidget
{
    public function component(): string
    {
        return ExtensionHealthFilamentWidget::class;
    }

    public function key(): string
    {
        return 'extension-health';
    }
}
