<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Pages\Actions;

use Capell\Admin\Actions\Pages\BulkPublishPagesAction;
use Capell\Admin\Actions\Publishing\PreviewBulkPublicationTransitionAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\Publishing\PublicationTransition;
use Capell\Core\Enums\Publishing\PublicationTransitionOutcome;
use Capell\Core\Models\Page;
use Carbon\CarbonImmutable;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Override;

class BulkPublishPagesBulkAction extends BulkAction
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::bulk_actions.publish_pages'))
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('capell-admin::bulk_actions.publish_pages_heading'))
            ->modalDescription(__('capell-admin::bulk_actions.publish_pages_description'))
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

                $result = BulkPublishPagesAction::run($records, $actor);

                $notification = Notification::make()
                    ->title(__('capell-admin::bulk_actions.publish_pages_done', [
                        'published' => $result->changed(),
                        'skipped' => $records->count() - $result->changed(),
                    ]));

                $skipped = collect($result->records)
                    ->reject(fn (array $row): bool => $row['result']->outcome === PublicationTransitionOutcome::Changed);

                if ($skipped->isNotEmpty()) {
                    $body = $skipped
                        ->map(fn (array $row): string => sprintf(
                            '• %s — %s',
                            $row['label'],
                            __('capell-admin::bulk_actions.outcome_' . $row['result']->outcome->value),
                        ))
                        ->implode("\n");
                    $notification->body($body);
                }

                $result->changed() > 0 ? $notification->success() : $notification->warning();
                $notification->send();
            });
    }

    public static function getDefaultName(): ?string
    {
        return 'bulk-publish-pages';
    }

    /** @param  Collection<int, Model&Pageable<Page>>  $records */
    public static function canBulkPublish(Collection $records): bool
    {
        return $records->isNotEmpty();
    }
}
