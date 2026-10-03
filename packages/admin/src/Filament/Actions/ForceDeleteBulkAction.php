<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions;

use Capell\Admin\Actions\ValidateForceDeleteAction;
use Capell\Admin\Filament\Contracts\ValidatesDelete;
use Override;

final class ForceDeleteBulkAction extends \Filament\Actions\ForceDeleteBulkAction
{
    #[Override]
    public function isConfirmationRequired(): bool
    {
        return true;
    }

    #[Override]
    public function shouldFetchSelectedRecords(): bool
    {
        return true;
    }

    #[Override]
    public function callBefore(): mixed
    {
        $livewire = $this->getLivewire();
        $validator = $livewire instanceof ValidatesDelete ? $livewire : null;

        // Validate the whole selection before deleting any of its records.
        foreach ($this->getSelectedRecords() as $record) {
            if (! ValidateForceDeleteAction::run($record, $validator)) {
                $this->halt();
            }
        }

        return parent::callBefore();
    }
}
