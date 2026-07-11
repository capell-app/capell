<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Contracts;

use Capell\Core\Enums\FrontendRuntime;

interface PagePresentation
{
    public function pageType(): string;

    public function runtime(): FrontendRuntime;

    public function component(): string;
}
