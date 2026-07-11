<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Actions;

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Marketplace\Actions\BuildMarketplaceInstallOperationsSummaryAction;
use Capell\Marketplace\Filament\Pages\MarketplacePackageOperationsPage;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

final class MarketplaceInstallOperationsAction
{
    public static function make(): Action
    {
        return Action::make('marketplaceInstallOperations')
            ->label((string) __('capell-marketplace::marketplace.operations.widget_heading'))
            ->icon(Heroicon::OutlinedQueueList)
            ->color('gray')
            ->badge(fn (): ?string => self::operationsCount() > 0 ? number_format(self::operationsCount()) : null)
            ->badgeColor(fn (): string => self::attentionCount() > 0 ? 'danger' : 'info')
            ->visible(fn (): bool => ExtensionsPage::canAccess() && self::operationsCount() > 0)
            ->authorize(fn (): bool => ExtensionsPage::canAccess())
            ->url(fn (): string => MarketplacePackageOperationsPage::getUrl([
                'tab' => self::attentionCount() > 0 ? 'failed' : 'active',
            ]));
    }

    private static function operationsCount(): int
    {
        return BuildMarketplaceInstallOperationsSummaryAction::run()->operationsCount;
    }

    private static function attentionCount(): int
    {
        return BuildMarketplaceInstallOperationsSummaryAction::run()->attentionCount;
    }
}
