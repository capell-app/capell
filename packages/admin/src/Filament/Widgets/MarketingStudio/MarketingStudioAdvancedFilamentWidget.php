<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\MarketingStudio;

use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Data\MarketingStudioActionData;
use Capell\Admin\Enums\MarketingStudioSectionEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Filament\Widgets\Widget;

final class MarketingStudioAdvancedFilamentWidget extends Widget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;

    /** @var list<string> */
    protected static array $rolesConfigKeys = ['admin', 'super_admin'];

    protected static string $settingsKey = 'marketing_studio.advanced';

    protected string $view = 'capell-admin::widgets.marketing-studio.advanced';

    protected int|string|array $columnSpan = ['default' => null, 'md' => 12, 'lg' => 12, 'xl' => 12];

    protected static ?int $sort = 50;

    /**
     * @return list<MarketingStudioActionData>
     */
    public function actions(): array
    {
        return CapellAdmin::getMarketingStudioActions()[MarketingStudioSectionEnum::Advanced->value] ?? [];
    }
}
