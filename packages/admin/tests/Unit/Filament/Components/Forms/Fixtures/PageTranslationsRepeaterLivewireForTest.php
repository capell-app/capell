<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Components\Forms\Fixtures;

use Capell\Admin\Filament\Contracts\HasPageResource;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Tests\Fixtures\Livewire;

final class PageTranslationsRepeaterLivewireForTest extends Livewire implements HasPageResource
{
    public static function getResource(): string
    {
        return PageResource::class;
    }
}
