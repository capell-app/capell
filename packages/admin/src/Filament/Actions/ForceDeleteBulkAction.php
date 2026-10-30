<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions;

use Capell\Admin\Actions\ValidateForceDeleteAction;
use Capell\Admin\Filament\Contracts\ValidatesDelete;
use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\SiteAccess;
use Override;

final class ForceDeleteBulkAction extends \Filament\Actions\ForceDeleteBulkAction
{
    #[Override]
    public function hasDatabaseTransactions(): bool
    {
        if ($this->getSelectedRecords()->contains(fn ($record): bool => $record instanceof Site)) {
            return true;
        }

        return parent::hasDatabaseTransactions();
    }

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

        // Lock sites in stable order before checking dependencies and deleting.
        foreach ($this->getSelectedRecords()->filter(fn ($record): bool => $record instanceof Site)->sortBy(fn (Site $site): int => (int) $site->getKey()) as $site) {
            SiteAccess::current()->query(Site::class)->withTrashed()->whereKey($site->getKey())->lockForUpdate()->firstOrFail();
        }

        // Validate the whole selection before deleting any of its records.
        foreach ($this->getSelectedRecords() as $record) {
            if (! ValidateForceDeleteAction::run($record, $validator)) {
                $this->halt();
            }
        }

        return parent::callBefore();
    }
}
