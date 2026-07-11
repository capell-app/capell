<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Fixtures;

use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Tests\Fixtures\Livewire;

final class AdminResourceConfigurationFormLivewire extends Livewire
{
    public function getResource(): string
    {
        return PageResource::class;
    }
}
