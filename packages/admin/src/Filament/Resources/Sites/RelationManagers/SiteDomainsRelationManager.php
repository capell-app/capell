<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Sites\RelationManagers;

use BackedEnum;
use Capell\Admin\Filament\Concerns\HasConfiguredRelationManagerForm;
use Capell\Admin\Filament\Concerns\HasConfiguredRelationManagerTable;
use Capell\Admin\Filament\Concerns\HasRelationManagerBadge;
use Capell\Admin\Filament\Concerns\Validate\SiteDomainValidation;
use Capell\Admin\Filament\Resources\Sites\Schemas\SiteDomainForm;
use Capell\Admin\Filament\Resources\Sites\Tables\SiteDomainsTable;
use Capell\Core\Models;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property Models\Site $ownerRecord
 */
class SiteDomainsRelationManager extends RelationManager
{
    use HasConfiguredRelationManagerForm;
    use HasConfiguredRelationManagerTable;
    use HasRelationManagerBadge;
    use SiteDomainValidation;

    protected static string|BackedEnum|null $icon = 'heroicon-o-globe-alt';

    protected static ?string $recordTitleAttribute = 'full_url';

    protected static string $relationship = 'siteDomains';

    protected static string $formConfigurator = SiteDomainForm::class;

    protected static string $tableConfigurator = SiteDomainsTable::class;

    public static function getLabel(): ?string
    {
        return __('capell-admin::generic.site_domain');
    }

    #[Override]
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('capell-admin::tab.site_domains');
    }

    #[Override]
    protected static function getPluralModelLabel(): string
    {
        return __('capell-admin::generic.site_domains');
    }
}
