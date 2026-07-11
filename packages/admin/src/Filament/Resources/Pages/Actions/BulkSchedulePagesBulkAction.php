<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Pages\Actions;

use Capell\Core\Models\Page;
use Carbon\CarbonImmutable;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Override;

/**
 * Schedule a batch of pages to publish at a specific future date.
 *
 * Different from BulkPublishPagesBulkAction (which publishes immediately),
 * this action takes a target datetime from the confirmation modal and writes
 * it to visible_from on each authorized page. Skips pages where the actor
 * lacks Update permission, with structured per-page feedback.
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
                $scheduled = 0;
                $skipped = 0;
                $skippedPages = [];

                DB::transaction(function () use ($records, $actor, $publishAt, &$scheduled, &$skipped, &$skippedPages): void {
                    foreach ($records as $page) {
                        if (! $page instanceof Page) {
                            continue;
                        }

                        if ($page->trashed()) {
                            $skipped++;
                            $skippedPages[] = [
                                'id' => (int) $page->getKey(),
                                'name' => (string) $page->getAttribute('name'),
                                'reason' => 'trashed',
                            ];

                            continue;
                        }

                        if (! Gate::forUser($actor)->allows('update', $page)) {
                            $skipped++;
                            $skippedPages[] = [
                                'id' => (int) $page->getKey(),
                                'name' => (string) $page->getAttribute('name'),
                                'reason' => 'unauthorized',
                            ];

                            continue;
                        }

                        $page->visible_from = $publishAt;
                        $page->save();
                        $scheduled++;
                    }
                });

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
