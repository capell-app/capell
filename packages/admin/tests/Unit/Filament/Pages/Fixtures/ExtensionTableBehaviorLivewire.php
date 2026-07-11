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

final class ExtensionTableBehaviorLivewire extends Component implements ExtensionTableDataSource, HasSchemas, HasTable
{
    use InteractsWithSchemas;
    use InteractsWithTable;

    /** @var list<array<string, mixed>> */
    public array $records = [];

    /** @var list<array{search: string|null, productGroup: string|null, filters: array<string, string|null>}> */
    public array $calls = [];

    /** @var list<string> */
    public array $rememberedPackageNames = [];

    public int $refreshCount = 0;

    /** @var list<string> */
    public array $groups = ['Content', 'Analytics'];

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
        $this->calls[] = [
            'search' => $search,
            'productGroup' => $productGroup,
            'filters' => $filters,
        ];

        return $this->records;
    }

    /** @return list<string> */
    public function getProductGroups(): array
    {
        return $this->groups;
    }

    public function rememberCurrentExtensionTablePosition(string $packageName): void
    {
        $this->rememberedPackageNames[] = $packageName;
    }

    public function refreshExtensionOperations(): void
    {
        $this->refreshCount++;
    }
}
