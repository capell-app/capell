<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions;

use Capell\Admin\Actions\Sites\NotifySiteHasRetainedRecordsAction;
use Capell\Admin\Actions\ValidateForceDeleteAction;
use Capell\Admin\Filament\Contracts\ValidatesDelete;
use Capell\Core\Exceptions\SiteHasRetainedRecordsException;
use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\SiteAccess;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Override;

final class ForceDeleteAction extends \Filament\Actions\ForceDeleteAction
{
    #[Override]
    public function hasDatabaseTransactions(): bool
    {
        return $this->getRecord() instanceof Site || parent::hasDatabaseTransactions();
    }

    #[Override]
    public function isConfirmationRequired(): bool
    {
        return true;
    }

    #[Override]
    public function callBefore(): mixed
    {
        $record = $this->getRecord();
        throw_unless($record instanceof Model, LogicException::class, 'Permanent deletion requires a model record.');

        if ($record instanceof Site) {
            SiteAccess::current()->query(Site::class)->withTrashed()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
        }

        $livewire = $this->getLivewire();
        if (! ValidateForceDeleteAction::run($record, $livewire instanceof ValidatesDelete ? $livewire : null)) {
            $this->halt();
        }

        return parent::callBefore();
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    #[Override]
    public function call(array $parameters = []): mixed
    {
        try {
            return parent::call($parameters);
        } catch (SiteHasRetainedRecordsException $siteHasRetainedRecordsException) {
            NotifySiteHasRetainedRecordsAction::run($siteHasRetainedRecordsException);

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }
}
