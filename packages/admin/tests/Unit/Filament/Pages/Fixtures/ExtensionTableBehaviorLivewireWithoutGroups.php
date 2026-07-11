<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Pages\Fixtures;

use Capell\Admin\Contracts\Extensions\ExtensionTableDataSource;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Contracts\TranslatableContentDriver;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Component;

final class ExtensionTableBehaviorLivewireWithoutGroups extends Component implements ExtensionTableDataSource, HasSchemas, HasTable
{
    use InteractsWithSchemas;
    use InteractsWithTable;

    public function mountTableForExtensionsTableBehaviorTest(Table $table): void
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

    public function getExtensionsData(?string $search = null, ?string $productGroup = null, array $filters = []): array
    {
        return [];
    }
}
