<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extenders;

interface PageResourceWidgetExtender
{
    public const string TAG = 'capell-admin:page-resource-widget-extender';

    /** @return array<int, class-string> */
    public function getWidgets(): array;
}
