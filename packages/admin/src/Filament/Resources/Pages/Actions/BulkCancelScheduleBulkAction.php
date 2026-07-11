<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Pages\Actions;

use Capell\Admin\Actions\Pages\BulkCancelScheduleAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Page;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;
use Override;

class BulkCancelScheduleBulkAction extends BulkAction
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::bulk_actions.cancel_schedule'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->tooltip(__('capell-admin::bulk_actions.cancel_schedule_tooltip'))
            ->requiresConfirmation()
            ->modalHeading(__('capell-admin::bulk_actions.cancel_schedule_modal_heading'))
            ->modalDescription(__('capell-admin::bulk_actions.cancel_schedule_modal_description'))
            ->action(function (Collection $records): void {
                /** @var User $actor */
                $actor = auth()->user();

                /** @var Collection<int, Page&Pageable> $pages */
                $pages = $records;

                $result = BulkCancelScheduleAction::run($pages, $actor);

                if ($result['cancelled'] === 0) {
                    Notification::make()
                        ->title(__('capell-admin::bulk_actions.cancel_schedule_none_cancelled'))
                        ->body(__('capell-admin::bulk_actions.cancel_schedule_none_cancelled_body'))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('capell-admin::bulk_actions.cancel_schedule_done', [
                        'cancelled' => $result['cancelled'],
                        'skipped' => $result['skipped'],
                    ]))
                    ->success()
                    ->send();
            });
    }

    public static function getDefaultName(): ?string
    {
        return 'bulk-cancel-schedule';
    }
}
