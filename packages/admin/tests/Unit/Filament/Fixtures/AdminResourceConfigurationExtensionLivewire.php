<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Fixtures;

use Capell\Admin\Contracts\Extensions\ExtensionTableDataSource;
use Filament\Support\Contracts\TranslatableContentDriver;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Component;

final class AdminResourceConfigurationExtensionLivewire extends Component implements ExtensionTableDataSource, HasTable
{
    use InteractsWithTable;

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
        return [
            [
                'id' => 'capell-app/example',
                'name' => 'Example',
                'label' => 'Example',
                'packageName' => 'capell-app/example',
                'installed' => false,
                'enabled' => false,
                'core' => false,
                'tags' => ['Content'],
            ],
        ];
    }

    /** @return list<string> */
    public function getProductGroups(): array
    {
        return ['Content', 'Commerce'];
    }
}
