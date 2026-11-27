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
use Illuminate\Support\Collection;
use Override;
use Throwable;

final class ForceDeleteBulkAction extends \Filament\Actions\ForceDeleteBulkAction
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Filament's own bulk delete swallows every exception per record and reports a generic failure.
        // Let the retained-records refusal through so call() can explain it; everything else keeps Filament's behaviour.
        $this->using(function (self $action, Collection $records): void {
            $isFirstException = true;

            $records->each(static function (Model $record) use ($action, &$isFirstException): void {
                try {
                    $record->forceDelete() || $action->reportBulkProcessingFailure();
                } catch (SiteHasRetainedRecordsException $siteHasRetainedRecordsException) {
                    throw $siteHasRetainedRecordsException;
                } catch (Throwable $exception) {
                    $action->reportBulkProcessingFailure();

                    if ($isFirstException) {
                        report($exception);
                        $isFirstException = false;
                    }
                }
            });
        });
    }

    #[Override]
    public function hasDatabaseTransactions(): bool
    {
        if ($this->getSelectedRecords()->contains(fn (mixed $record): bool => $record instanceof Site)) {
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
        foreach ($this->getSelectedRecords()->filter(fn (mixed $record): bool => $record instanceof Site)->sortBy(fn (Site $site): int => (int) $site->getKey()) as $site) {
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

    /**
     * @param  array<string, mixed>  $parameters
     */
    #[Override]
    public function call(array $parameters = []): mixed
    {
        try {
            return parent::call($parameters);
        } catch (SiteHasRetainedRecordsException $siteHasRetainedRecordsException) {
            NotifySiteHasRetainedRecordsAction::run($siteHasRetainedRecordsException, bulk: true);

            // Roll back sites already deleted earlier in the selection so the selection is all or nothing.
            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }
}
