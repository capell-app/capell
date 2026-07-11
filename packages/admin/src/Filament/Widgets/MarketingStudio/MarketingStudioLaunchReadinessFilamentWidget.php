<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\MarketingStudio;

use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Data\MarketingStudioActionData;
use Capell\Admin\Enums\MarketingStudioSectionEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Filament\Widgets\Widget;

final class MarketingStudioLaunchReadinessFilamentWidget extends Widget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;

    /** @var list<string> */
    protected static array $rolesConfigKeys = ['editor', 'admin', 'super_admin'];

    protected static string $settingsKey = 'marketing_studio.launch_readiness';

    protected string $view = 'capell-admin::widgets.marketing-studio.launch-readiness';

    protected int|string|array $columnSpan = ['default' => null, 'md' => 12, 'lg' => 6, 'xl' => 6];

    protected static ?int $sort = 30;

    /**
     * @return list<MarketingStudioActionData>
     */
    public function checks(): array
    {
        return array_values(collect(CapellAdmin::getMarketingStudioActions())
            ->only([
                MarketingStudioSectionEnum::Campaigns->value,
                MarketingStudioSectionEnum::Forms->value,
                MarketingStudioSectionEnum::Audience->value,
            ])
            ->flatten(1)
            ->filter(fn (mixed $action): bool => $action instanceof MarketingStudioActionData)
            ->values()
            ->all());
    }
}
