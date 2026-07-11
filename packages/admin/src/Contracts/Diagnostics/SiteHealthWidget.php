<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Diagnostics;

interface SiteHealthWidget
{
    public const string TAG = 'capell.admin.site_health_widget';

    public function component(): string;

    public function key(): string;
}
