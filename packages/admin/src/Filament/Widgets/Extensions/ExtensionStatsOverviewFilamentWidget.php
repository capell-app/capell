<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Extensions;

use Capell\Admin\Actions\Extensions\BuildExtensionOperationsSummaryAction;
use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Data\Extensions\ExtensionOperationsSummaryData;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Override;

final class ExtensionStatsOverviewFilamentWidget extends StatsOverviewWidget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;

    /** Lets the admin stylesheet compact a tile on a phone, where three share a row and a label wraps rather than clips. */
    private const string TILE_CLASS = 'capell-extension-stat';

    /** @var list<string> */
    protected static array $rolesConfigKeys = [];

    protected static string $settingsKey = 'extensions.stats';

    protected ?string $heading = null;

    protected ?string $description = null;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    /** @var int|array<string, int|null>|null */
    protected int|array|null $columns = [
        'default' => 3,
        'md' => 5,
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
            ->extraAttributes(['class' => self::TILE_CLASS])
            ->icon('heroicon-o-cube')
            ->description(trans_choice('capell-admin::generic.extension_stats.installed', $summary->installedCount, ['count' => $summary->installedCount]))
            ->url(ExtensionsPage::getUrl(['installed-extensionsFilters' => ['installed_status' => ['value' => '1']]]))
            ->color('primary');
    }

    private function uninstalledStat(ExtensionOperationsSummaryData $summary): Stat
    {
        return Stat::make(__('capell-admin::generic.extension_operations_tab_uninstalled'), (string) $summary->uninstalledCount)
            ->extraAttributes(['class' => self::TILE_CLASS])
            ->icon('heroicon-o-archive-box')
            ->description(trans_choice('capell-admin::generic.extension_stats.uninstalled', $summary->uninstalledCount, ['count' => $summary->uninstalledCount]))
            ->url(ExtensionsPage::getUrl(['installed-extensionsFilters' => ['installed_status' => ['value' => '0']]]))
            ->color('gray');
    }

    private function needsAttentionStat(ExtensionOperationsSummaryData $summary): Stat
    {
        return Stat::make(__('capell-admin::generic.extension_operations_tab_needs_attention'), (string) $summary->needsAttentionCount)
            ->extraAttributes(['class' => self::TILE_CLASS])
            ->icon('heroicon-o-exclamation-triangle')
            ->description(trans_choice('capell-admin::generic.extension_stats.needs_attention', $summary->needsAttentionCount, ['count' => $summary->needsAttentionCount]))
            ->descriptionIcon($summary->needsAttentionCount > 0 ? 'heroicon-m-exclamation-circle' : 'heroicon-m-check-circle')
            ->color($summary->needsAttentionCount > 0 ? 'warning' : 'success');
    }

    private function updatesStat(ExtensionOperationsSummaryData $summary): Stat
    {
        return Stat::make(__('capell-admin::generic.extension_operations_tab_updates'), (string) $summary->updatesCount)
            ->extraAttributes(['class' => self::TILE_CLASS])
            ->icon('heroicon-o-arrow-path')
            ->description(trans_choice('capell-admin::generic.extension_stats.updates', $summary->updatesCount, ['count' => $summary->updatesCount]))
            ->descriptionIcon($summary->updatesCount > 0 ? 'heroicon-m-arrow-up-circle' : 'heroicon-m-check-circle')
            ->color($summary->updatesCount > 0 ? 'warning' : 'success');
    }

    private function blockedStat(ExtensionOperationsSummaryData $summary): Stat
    {
        return Stat::make(__('capell-admin::generic.extension_operations_tab_blocked'), (string) $summary->blockedCount)
            ->extraAttributes(['class' => self::TILE_CLASS])
            ->icon('heroicon-o-no-symbol')
            ->description(trans_choice('capell-admin::generic.extension_stats.blocked', $summary->blockedCount, ['count' => $summary->blockedCount]))
            ->descriptionIcon($summary->blockedCount > 0 ? 'heroicon-m-no-symbol' : 'heroicon-m-check-circle')
            ->color($summary->blockedCount > 0 ? 'danger' : 'success');
    }
}
