<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard;

use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Data\Dashboard\CapellOverviewStatData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Filament\Widgets\Widget;
use Livewire\Attributes\Computed;

final class PageStatusFilamentWidget extends Widget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;

    /** @var list<string> */
    protected static array $rolesConfigKeys = [];

    protected static string $settingsKey = 'page_status';

    protected string $view = 'capell-admin::widgets.page-status';

    protected int|string|array $columnSpan = [
        'default' => 'full',
    ];

    protected static ?int $sort = 11;

    /**
     * @return list<CapellOverviewStatData>
     */
    #[Computed(persist: true, seconds: 60)]
    public function stats(): array
    {
        return CapellAdmin::getOverviewStats();
    }
}
