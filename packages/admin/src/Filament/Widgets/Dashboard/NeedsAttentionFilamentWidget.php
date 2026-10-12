<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard;

use Capell\Admin\Actions\Dashboard\BuildDashboardAttentionAction;
use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Data\Reports\ReportFindingData;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Capell\Admin\Filament\Concerns\HasDashboardDateRange;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Filament\Widgets\Widget;
use Override;

final class NeedsAttentionFilamentWidget extends Widget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;
    use HasDashboardDateRange;

    protected static string $settingsKey = 'needs_attention';

    /** @var list<string> */
    protected static array $rolesConfigKeys = ['editor', 'admin', 'super_admin'];

    protected string $view = 'capell-admin::widgets.dashboard.needs-attention';

    protected int|string|array $columnSpan = 'full';

    #[Override]
    public static function canView(): bool
    {
        return self::canViewCheck() && PageResource::canViewAny();
    }

    /** @return list<ReportFindingData> */
    public function findings(): array
    {
        $actor = auth()->user();

        return $actor === null ? [] : BuildDashboardAttentionAction::run($actor, $this->getDashboardSiteId());
    }
}
