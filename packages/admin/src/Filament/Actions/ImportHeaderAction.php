<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions;

use Capell\Admin\Data\ImportEntryData;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Support\ImportEntryRegistry;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;

final class ImportHeaderAction
{
    /**
     * @param  class-string  $pageClass
     */
    public static function make(string $pageClass): ActionGroup
    {
        $registry = resolve(ImportEntryRegistry::class);
        $registeredEntries = $registry->registeredForPage($pageClass);
        $entries = $registry->forPage($pageClass);

        $actions = $registeredEntries === []
            ? [self::migrationAssistantRequiredAction()]
            : array_map(fn (ImportEntryData $entry): Action|ActionGroup => $entry->makeAction(), $entries);

        return ActionGroup::make($actions)
            ->label(__('capell-admin::exchanger.import.action_label'))
            ->icon('heroicon-o-arrow-up-tray')
            ->button()
            ->color('gray')
            ->dropdownPlacement('bottom-start')
            ->visible(fn (): bool => resolve(AdminSettings::class)->enable_import_export && $actions !== []);
    }

    private static function migrationAssistantRequiredAction(): Action
    {
        return Action::make('migrationAssistantRequired')
            ->label(__('capell-admin::exchanger.import.migration_assistant_required'))
            ->icon('heroicon-o-puzzle-piece')
            ->modalHeading(__('capell-admin::exchanger.import.migration_assistant_required'))
            ->modalDescription(__('capell-admin::exchanger.import.migration_assistant_required_description'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('capell-admin::button.close'));
    }
}
