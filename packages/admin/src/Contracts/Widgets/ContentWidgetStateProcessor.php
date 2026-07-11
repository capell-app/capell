<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Widgets;

interface ContentWidgetStateProcessor
{
    public const string TAG = 'capell-admin:content-widget-state-processors';

    /**
     * @param  array<int|string, mixed>  $widget
     * @return array<int|string, mixed>
     */
    public function process(string $widgetKey, array $widget): array;
}
