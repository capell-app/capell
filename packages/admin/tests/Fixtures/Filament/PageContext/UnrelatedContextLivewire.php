<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\PageContext;

use Capell\Admin\Tests\Fixtures\Livewire;

class UnrelatedContextLivewire extends Livewire
{
    public static function getResource(): string
    {
        return UnrelatedContextResource::class;
    }
}
