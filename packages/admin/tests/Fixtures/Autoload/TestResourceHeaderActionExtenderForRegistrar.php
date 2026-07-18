<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Autoload;

use Capell\Admin\Contracts\Extenders\ResourceHeaderActionExtender;
use Filament\Actions\Action;

final class TestResourceHeaderActionExtenderForRegistrar implements ResourceHeaderActionExtender
{
    public function supports(string $pageClass): bool
    {
        return true;
    }

    /** @return array<int, Action> */
    public function actions(): array
    {
        return [];
    }
}
