<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\MarketingStudio;

use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Data\MarketingStudioActionData;
use Capell\Admin\Enums\MarketingStudioSectionEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Filament\Widgets\Widget;

final class MarketingStudioWorkQueueFilamentWidget extends Widget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;

    /** @var list<string> */
    protected static array $rolesConfigKeys = ['editor', 'admin', 'super_admin'];

    protected static string $settingsKey = 'marketing_studio.work_queue';

    protected string $view = 'capell-admin::widgets.marketing-studio.work-queue';

    protected int|string|array $columnSpan = ['default' => null, 'md' => 12, 'lg' => 4, 'xl' => 4];

    protected static ?int $sort = 20;

    /**
     * @return list<MarketingStudioActionData>
     */
    public function items(): array
    {
        return CapellAdmin::getMarketingStudioActions()[MarketingStudioSectionEnum::WorkQueue->value] ?? [];
    }
}
