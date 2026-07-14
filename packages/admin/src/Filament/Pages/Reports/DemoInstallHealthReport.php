<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Reports;

use Capell\Admin\Actions\Reports\BuildDemoInstallHealthReportAction;
use Filament\Actions\Action;

final class DemoInstallHealthReport extends AbstractCoreReportPage
{
    public const string REPORT_KEY = 'core.demo_install_health';

    protected const string REPORT_ACTION = BuildDemoInstallHealthReportAction::class;

    protected static ?string $slug = 'reports/demo-install-health';

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('rerun')
                ->label(__('capell-admin::reports.demo_install_health_rerun'))
                ->icon('heroicon-o-arrow-path')
                ->action(fn (): mixed => $this->dispatch('$refresh')),
        ];
    }
}
