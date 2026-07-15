<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Pages\Actions;

use Capell\Admin\Actions\Pages\BulkPublishPagesAction;
use Capell\Admin\Actions\Publishing\PreviewBulkPublicationTransitionAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\Publishing\PublicationTransition;
use Capell\Core\Models\Page;
use Carbon\CarbonImmutable;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
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
            ->modalContent(function (Collection $records): Factory|View {
                /** @var User $actor */
                $actor = auth()->user();

                return view('capell-admin::filament.actions.bulk-publication-preview', [
                    'preview' => PreviewBulkPublicationTransitionAction::run(
                        records: $records,
                        actor: $actor,
                        transition: PublicationTransition::PublishNow,
                        now: CarbonImmutable::now(),
                    ),
                ]);
            })
            ->action(function (Collection $records): void {
                /** @var User $actor */
                $actor = auth()->user();

                /** @var Collection<int, Page&Pageable> $pages */
                $pages = $records;

                $result = BulkPublishPagesAction::run($pages, $actor);

                if ($result->changed() === 0) {
                    Notification::make()
                        ->title(__('capell-admin::bulk_actions.publish_now_none_published'))
                        ->body(__('capell-admin::bulk_actions.publish_now_none_published_body'))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('capell-admin::bulk_actions.publish_now_done', [
                        'published' => $result->changed(),
                        'skipped' => $records->count() - $result->changed(),
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
