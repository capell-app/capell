<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Extensions;

use Capell\Admin\Actions\Extensions\BuildExtensionOperationsSummaryAction;
use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Data\Extensions\ExtensionOperationsSummaryData;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Override;

final class ExtensionStatsOverviewFilamentWidget extends StatsOverviewWidget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;

    /** @var list<string> */
    protected static array $rolesConfigKeys = [];

    protected static string $settingsKey = 'extensions.stats';

    protected ?string $heading = null;

    protected ?string $description = null;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    /** @var int|array<string, int|null>|null */
    protected int|array|null $columns = [
        'default' => 2,
        'md' => 3,
        'lg' => 5,
    ];

    protected static ?int $sort = 19;

    #[Override]
    protected function getStats(): array
    {
        $summary = BuildExtensionOperationsSummaryAction::run();

        return [
            $this->installedStat($summary),
            $this->uninstalledStat($summary),
            $this->needsAttentionStat($summary),
            $this->updatesStat($summary),
            $this->blockedStat($summary),
        ];
    }

    private function installedStat(ExtensionOperationsSummaryData $summary): Stat
    {
        return Stat::make(__('capell-admin::generic.extension_operations_tab_installed'), (string) $summary->installedCount)
            ->icon('heroicon-o-cube')
            ->color('primary');
    }

    private function uninstalledStat(ExtensionOperationsSummaryData $summary): Stat
    {
        return Stat::make(__('capell-admin::generic.extension_operations_tab_uninstalled'), (string) $summary->uninstalledCount)
            ->icon('heroicon-o-archive-box')
            ->color('gray');
    }

    private function needsAttentionStat(ExtensionOperationsSummaryData $summary): Stat
    {
        return Stat::make(__('capell-admin::generic.extension_operations_tab_needs_attention'), (string) $summary->needsAttentionCount)
            ->icon('heroicon-o-exclamation-triangle')
            ->color($summary->needsAttentionCount > 0 ? 'warning' : 'success');
    }

    private function updatesStat(ExtensionOperationsSummaryData $summary): Stat
    {
        return Stat::make(__('capell-admin::generic.extension_operations_tab_updates'), (string) $summary->updatesCount)
            ->icon('heroicon-o-arrow-path')
            ->color($summary->updatesCount > 0 ? 'warning' : 'success');
    }

    private function blockedStat(ExtensionOperationsSummaryData $summary): Stat
    {
        return Stat::make(__('capell-admin::generic.extension_operations_tab_blocked'), (string) $summary->blockedCount)
            ->icon('heroicon-o-no-symbol')
            ->color($summary->blockedCount > 0 ? 'danger' : 'success');
    }
}
