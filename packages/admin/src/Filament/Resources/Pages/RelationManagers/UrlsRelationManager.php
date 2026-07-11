<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Pages\RelationManagers;

use BackedEnum;
use Capell\Admin\Filament\Concerns\HasConfiguredRelationManagerForm;
use Capell\Admin\Filament\Concerns\HasConfiguredRelationManagerTable;
use Capell\Admin\Filament\Concerns\HasRelationManagerBadge;
use Capell\Admin\Filament\Resources\Pages\Schemas\PageUrlForm;
use Capell\Admin\Filament\Resources\Pages\Tables\PageUrlsTable;
use Capell\Core\Models\Page;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;
use Override;
use ReflectionProperty;

/**
 * @property-read Page $ownerRecord
 */
class UrlsRelationManager extends RelationManager
{
    use HasConfiguredRelationManagerForm;
    use HasConfiguredRelationManagerTable;
    use HasRelationManagerBadge;

    protected static string|BackedEnum|null $icon = 'heroicon-o-link';

    protected static string $relationship = 'pageUrls';

    protected static string $formConfigurator = PageUrlForm::class;

    protected static string $tableConfigurator = PageUrlsTable::class;

    #[Override]
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('capell-admin::tab.urls');
    }

    #[On('refresh-relation')]
    public function loadProductsTable(): void
    {
        // Hacky fix to prevent error if relation manager not loaded and event triggered
        $rp = new ReflectionProperty(static::class, 'table');

        if (! $rp->isInitialized($this)) {
            parent::bootedInteractsWithTable();
        }
    }
}
