<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\PageContext;

use Capell\Admin\Filament\Contracts\HasPageResource;
use Capell\Admin\Tests\Fixtures\Livewire;

class CustomPageContextLivewire extends Livewire implements HasPageResource
{
    public static function getResource(): string
    {
        return CustomPageContextResource::class;
    }
}
