<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions\Table;

use Capell\Admin\Filament\Actions\Concerns\CanReplicateSite;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Core\Models\Site;
use Filament\Actions\Action;
use Filament\Actions\ReplicateAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
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

        $this->modal()
            ->slideOver()
            ->modalWidth(Width::ScreenLarge)
            ->schema(fn (): array => $this->getReplicaFormSchema($submitAction))
            ->mutateRecordDataUsing(fn (Site $record, array $data): array => $this->mutateReplicaRecordData($record, $data))
            ->submit(null)
            ->modalSubmitAction(false)
            ->modalCancelAction(false)
            ->action(fn (): Site => $this->replicateSiteAction())
            ->closeModalByClickingAway(false)
            ->successNotificationTitle(fn (): string => __('capell-admin::message.replicate_success', ['name' => $this->getRecordTitle()]))
            ->successNotification(
                fn (Notification $notification, Site $replica): Notification => $notification->actions([
                    Action::make('editSite')
                        ->icon('heroicon-o-pencil-square')
                        ->label(__('capell-admin::button.edit'))
                        ->url(SiteResource::getUrl('edit', ['record' => $replica])),
                    Action::make('cache-site')
                        ->label(__('capell-admin::button.cache_site'))
                        ->icon('heroicon-o-arrow-path')
                        ->action(function (Action $action) use ($replica): void {
                            Artisan::call('capell:static-site', ['--site' => $replica->getKey()]);
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
