<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Widgets;

use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Capell\Marketplace\Actions\BuildMarketplaceCommercialWarningsAction;
use Capell\Marketplace\Data\MarketplaceCommercialWarningData;
use Capell\Marketplace\Filament\Pages\MarketplacePage;
use Capell\Marketplace\Filament\Pages\MarketplacePurchasesPage;
use Filament\Widgets\Widget;
use Override;

final class MarketplaceCommercialWarningFilamentWidget extends Widget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;

    /** @var list<string> */
    protected static array $rolesConfigKeys = [];

    protected static string $settingsKey = '';

    protected string $view = 'capell-marketplace::filament.widgets.commercial-warning';

    /** @var int|string|array<string, int|string|null> */
    protected int|string|array $columnSpan = ['default' => 'full'];

    protected static ?int $sort = 17;

    #[Override]
    public static function canView(): bool
    {
        return MarketplacePage::canAccess();
    }

    /** @return list<MarketplaceCommercialWarningData> */
    public function warnings(): array
    {
        return BuildMarketplaceCommercialWarningsAction::run();
    }

    public function purchasesUrl(): string
    {
        return MarketplacePurchasesPage::getUrl();
    }
}
