<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions\Page;

use Capell\Admin\Filament\Actions\Concerns\CanReplicateSite;
use Capell\Admin\Filament\Resources\Sites\Pages\EditSite;
use Capell\Core\Models\Site;
use Filament\Actions\Action;
use Filament\Actions\ReplicateAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Override;

class ReplicateSiteAction extends ReplicateAction
{
    use CanReplicateSite;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $submitAction = $this->getModalSubmitAction();

        $this->slideOver()
            ->groupedIcon('heroicon-o-square-2-stack')
            ->schema(fn (): array => $this->getReplicaFormSchema($submitAction))
            ->mutateRecordDataUsing(
                fn (Site $record, array $data): array => $this->mutateReplicaRecordData($record, $data),
            )
            ->submit(null)
            ->modalSubmitAction(false)
            ->modalCancelAction(false)
            ->action(fn (): Site => $this->replicateSiteAction())
            ->closeModalByClickingAway(false)
            ->successNotificationTitle(
                fn (self $action): string|array => __(
                    'capell-admin::message.replicate_success',
                    ['name' => $action->getRecordTitle()],
                ),
            )
            ->successRedirectUrl(
                fn (Model $replica, EditSite $livewire): string => $livewire::getResource()::getUrl(
                    'edit',
                    ['record' => $replica],
                ),
            )
            ->successNotification(
                fn (Notification $notification, Site $record): Notification => $notification->actions([
                    Action::make('cache-site')
                        ->label(__('capell-admin::button.cache_site'))
                        ->icon('heroicon-o-arrow-path')
                        ->action(function (Action $action) use ($record): void {
                            Artisan::call('capell:static-site', ['--site' => $record->getKey()]);
                            Notification::make()
                                ->success()
                                ->title(__('capell-admin::message.cache_site_success'))
                                ->send();
                            $action->close();
                        }),
                ]),
            );
    }
}
