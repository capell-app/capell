<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Filament\Configurators\Fixtures;

use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Tests\Fixtures\Livewire as BaseLivewire;

final class PageConfiguratorTestLivewire extends BaseLivewire
{
    public function getResource(): string
    {
        return PageResource::class;
    }
}
