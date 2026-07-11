<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Pages\Actions;

use Capell\Admin\Actions\Pages\BulkPublishPagesAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Page;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;
use Override;

class BulkPublishNowBulkAction extends BulkAction
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::bulk_actions.publish_now'))
            ->icon(Heroicon::OutlinedRocketLaunch)
            ->color('success')
            ->tooltip(__('capell-admin::bulk_actions.publish_now_tooltip'))
            ->requiresConfirmation()
            ->modalHeading(__('capell-admin::bulk_actions.publish_now_modal_heading'))
            ->modalDescription(__('capell-admin::bulk_actions.publish_now_modal_description'))
            ->action(function (Collection $records): void {
                /** @var User $actor */
                $actor = auth()->user();

                /** @var Collection<int, Page&Pageable> $pages */
                $pages = $records;

                $result = BulkPublishPagesAction::run($pages, $actor);

                if ($result['published'] === 0) {
                    Notification::make()
                        ->title(__('capell-admin::bulk_actions.publish_now_none_published'))
                        ->body(__('capell-admin::bulk_actions.publish_now_none_published_body'))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('capell-admin::bulk_actions.publish_now_done', [
                        'published' => $result['published'],
                        'skipped' => $result['skipped'],
                    ]))
                    ->success()
                    ->send();
            });
    }

    public static function getDefaultName(): ?string
    {
        return 'bulk-publish-now';
    }
}
