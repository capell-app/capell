<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Extensions;

use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Contracts\Extensions\ExtensionTableDataSource;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Capell\Admin\Filament\Concerns\InteractsWithExtensionTableData;
use Capell\Admin\Filament\Pages\Extensions\Concerns\PreservesExtensionTablePosition;
use Capell\Admin\Filament\Pages\Extensions\Tables\ExtensionsTable;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Override;

final class InstalledExtensionsFilamentWidget extends TableWidget implements CapellFilamentWidgetContract, ExtensionTableDataSource
{
    use GatedByRoleAndSettings;
    use InteractsWithExtensionTableData;
    use PreservesExtensionTablePosition;

    /** @var list<string> */
    protected static array $rolesConfigKeys = [];

    protected static string $settingsKey = 'extensions.installed';

    protected int|string|array $columnSpan = ['default' => null, 'md' => 12, 'lg' => 12, 'xl' => 12];

    protected static ?int $sort = 30;

    #[Override]
    public function table(Table $table): Table
    {
        return ExtensionsTable::configure($table)
            ->heading(null)
            ->description(null)
            ->queryStringIdentifier('installed-extensions');
    }

    public function setProductGroup(?string $group): void
    {
        $this->activeProductGroup = $this->activeProductGroup === $group ? null : $group;
        $this->resetTable();
    }

    public function resetExtensionFilters(): void
    {
        $this->activeProductGroup = null;
        $this->resetTable();
    }
}
