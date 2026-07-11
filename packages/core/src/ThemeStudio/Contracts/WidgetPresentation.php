<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Contracts;

use Capell\Core\Enums\FrontendRuntime;

interface WidgetPresentation
{
    public function widgetType(): string;

    public function runtime(): FrontendRuntime;

    public function component(): string;
}
