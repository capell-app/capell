<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Components\Tables\Columns\Fixtures;

use Filament\Support\Contracts\TranslatableContentDriver;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Component;

final class DateColumnTableLivewire extends Component implements HasTable
{
    use InteractsWithTable;

    public function mountTableForDateColumnTest(Table $table): void
    {
        $this->table = $table;
    }

    public function table(Table $table): Table
    {
        return $table;
    }

    public function makeFilamentTranslatableContentDriver(): ?TranslatableContentDriver
    {
        return null;
    }
}
