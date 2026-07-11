<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages;

use Capell\Admin\Enums\AdminFormActionPositionEnum;
use Capell\Admin\Facades\CapellAdmin;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Pages\SettingsPage as BaseSettingsPage;
use Override;

abstract class AbstractAdminSettingsPage extends BaseSettingsPage
{
    /**
     * @return array<Action | ActionGroup>
     */
    #[Override]
    public function getFormActions(): array
    {
        if ($this->placesFormActionsAboveForm()) {
            return [];
        }

        return [
            $this->getSaveFormAction(),
        ];
    }

    /**
     * @return array<Action | ActionGroup>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        if (! $this->placesFormActionsAboveForm()) {
            return [];
        }

        return [
            $this->getSaveFormAction()
                ->submit(null)
                ->action(fn (): mixed => $this->save()),
        ];
    }

    protected function placesFormActionsAboveForm(): bool
    {
        return CapellAdmin::settings()->form_action_position === AdminFormActionPositionEnum::AboveForm;
    }
}
