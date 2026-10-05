<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions;

use Capell\Admin\Actions\RestorePageCascadeAction;
use Capell\Core\Models\Page;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
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
                $notices = [];
                foreach ($records as $record) {
                    try {
                        if (! $record instanceof Page) {
                            $this->reportBulkProcessingFailure();

                            continue;
                        }

                        if ($record->fresh()?->trashed() === false) {
                            continue;
                        }

                        $result = RestorePageCascadeAction::make()->restoreWithResult($record);
                        if (! $result->restored) {
                            $this->reportBulkProcessingFailure();
                        } elseif ($result->notice !== null) {
                            $notices[] = $result->notice;
                        }
                    } catch (Throwable $exception) {
                        $this->reportBulkProcessingFailure();
                        if ($isFirstException) {
                            report($exception);
                            $isFirstException = false;
                        }
                    }
                }

                if ($notices !== []) {
                    Notification::make()->title(__('capell-admin::message.recently_deleted_restored'))
                        ->body(implode("\n", array_unique($notices)))->warning()->send();
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
