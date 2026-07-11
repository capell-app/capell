<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Diagnostics;

interface SiteHealthWidgetWithParameters extends SiteHealthWidget
{
    /**
     * @return array<string, mixed>
     */
    public function parameters(?int $siteId): array;
}
