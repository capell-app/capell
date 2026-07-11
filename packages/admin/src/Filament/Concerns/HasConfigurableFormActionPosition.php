<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Capell\Admin\Enums\AdminFormActionPositionEnum;
use Capell\Admin\Facades\CapellAdmin;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;

trait HasConfigurableFormActionPosition
{
    /**
     * @return array<Action | ActionGroup>
     */
    abstract protected function getPositionedFormActions(): array;

    /**
     * @return array<Action | ActionGroup>
     */
    protected function getFormActions(): array
    {
        if ($this->placesFormActionsAboveForm()) {
            return [];
        }

        return $this->getPositionedFormActions();
    }

    /**
     * @return array<Action | ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        if (! $this->placesFormActionsAboveForm()) {
            return $this->getBaseHeaderActions();
        }

        return [
            ...$this->getPositionedHeaderFormActions(),
            ...$this->getBaseHeaderActions(),
        ];
    }

    protected function placesFormActionsAboveForm(): bool
    {
        return CapellAdmin::settings()->form_action_position === AdminFormActionPositionEnum::AboveForm;
    }

    /**
     * @return array<Action | ActionGroup>
     */
    protected function getPositionedHeaderFormActions(): array
    {
        return $this->getPositionedFormActions();
    }

    /**
     * @return array<Action | ActionGroup>
     */
    protected function getBaseHeaderActions(): array
    {
        return [];
    }
}
