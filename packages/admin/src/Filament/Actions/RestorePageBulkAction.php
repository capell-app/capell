<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions;

use Capell\Admin\Actions\RestorePageCascadeAction;
use Capell\Core\Models\Page;
use Filament\Actions\RestoreBulkAction;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Override;
use Throwable;

final class RestorePageBulkAction extends RestoreBulkAction
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->action(function (): void {
            $this->process(function (EloquentCollection|Collection|LazyCollection $records): void {
                $isFirstException = true;
                foreach ($records as $record) {
                    try {
                        if (! $record instanceof Page
                            || ($record->fresh()?->trashed() !== false && ! RestorePageCascadeAction::run($record))) {
                            $this->reportBulkProcessingFailure();
                        }
                    } catch (Throwable $exception) {
                        $this->reportBulkProcessingFailure();
                        if ($isFirstException) {
                            report($exception);
                            $isFirstException = false;
                        }
                    }
                }
            });
        });
    }

    #[Override]
    public function shouldFetchSelectedRecords(): bool
    {
        return true;
    }
}
