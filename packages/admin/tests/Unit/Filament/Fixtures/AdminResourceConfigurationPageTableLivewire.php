<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Fixtures;

use Capell\Admin\Filament\Contracts\HasPageResource;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Filament\Support\Contracts\TranslatableContentDriver;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Component;

final class AdminResourceConfigurationPageTableLivewire extends Component implements HasPageResource, HasTable
{
    use InteractsWithTable;

    /** @var list<string> */
    public array $hiddenTableColumns = [];

    public static function getResource(): string
    {
        return PageResource::class;
    }

    /** @return array<string, mixed> */
    public function getTableFilterState(string $name): array
    {
        return [];
    }

    public function isTableColumnToggledHidden(string $name): bool
    {
        return in_array($name, $this->hiddenTableColumns, true);
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
