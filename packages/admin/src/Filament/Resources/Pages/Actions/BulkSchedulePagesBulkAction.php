<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Pages\Actions;

use Capell\Admin\Actions\Publishing\RunBulkPublicationTransitionAction;
use Capell\Admin\Support\Publishing\PublicationSkipReason;
use Capell\Core\Enums\Publishing\PublicationTransition;
use Carbon\CarbonImmutable;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;
use Override;

/**
 * Schedule a batch of pages to publish at a specific future date.
 *
 * Different from BulkPublishPagesBulkAction (which publishes immediately), this
 * action takes a target datetime from the confirmation modal and delegates to the
 * Core publication state machine via RunBulkPublicationTransitionAction.
 *
 * Authorization, trashed-record rejection and date validation all happen inside
 * Core and surface as typed outcomes; the notification's skip reasons are mapped
 * from those outcomes rather than re-derived here.
 */
class BulkSchedulePagesBulkAction extends BulkAction
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::bulk_actions.schedule_pages'))
            ->icon(Heroicon::OutlinedClock)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('capell-admin::bulk_actions.schedule_pages_heading'))
            ->modalDescription(__('capell-admin::bulk_actions.schedule_pages_description'))
            ->schema([
                DateTimePicker::make('publish_at')
                    ->label(__('capell-admin::bulk_actions.schedule_pages_publish_at'))
                    ->required()
                    ->minDate(now())
                    ->seconds(false)
                    ->native(false),
            ])
            ->action(function (Collection $records, array $data): void {
                /** @var User $actor */
                $actor = auth()->user();

                $publishAt = CarbonImmutable::parse((string) $data['publish_at']);

                // Deliberately no batch transaction: the state machine transacts per
                // record, so a mid-batch failure leaves earlier pages scheduled and
                // reports the rest as failed. That partial success is the point — a
                // batch rollback would make the returned per-record outcomes untrue.
                // Contrast BulkMovePagesAction, which does wrap the batch because a
                // half-applied move breaks frontend routing.
                $preview = RunBulkPublicationTransitionAction::run(
                    records: $records,
                    actor: $actor,
                    transition: PublicationTransition::SchedulePublish,
                    now: CarbonImmutable::now(),
                    requestedTime: $publishAt,
                );

                $scheduled = $preview->changed();
                $skipped = $preview->blocked() + $preview->unchanged();
                $skippedPages = [];

                foreach ($preview->records as $record) {
                    $reason = PublicationSkipReason::for($record['result'], 'already_scheduled');

                    if ($reason === null) {
                        continue;
                    }

                    $skippedPages[] = [
                        'id' => (int) $record['id'],
                        'name' => $record['label'],
                        'reason' => $reason,
                    ];
                }

                $notification = Notification::make()
                    ->title(__('capell-admin::bulk_actions.schedule_pages_done', [
                        'scheduled' => $scheduled,
                        'skipped' => $skipped,
                    ]));

                if ($skippedPages !== []) {
                    $body = collect($skippedPages)
                        ->map(fn (array $row): string => sprintf(
                            '• %s — %s',
                            $row['name'],
                            __('capell-admin::bulk_actions.schedule_pages_reason_' . $row['reason']),
                        ))
                        ->implode("\n");
                    $notification->body($body);
                }

                $scheduled > 0 ? $notification->success() : $notification->warning();
                $notification->send();
            });
    }

    public static function getDefaultName(): ?string
    {
        return 'bulk-schedule-pages';
    }
}
