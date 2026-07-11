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
            ->action(function (Collection $records): void {
                /** @var User $actor */
                $actor = auth()->user();

                $result = BulkPublishPagesAction::make()->handle($records, $actor);

                $notification = Notification::make()
                    ->title(__('capell-admin::bulk_actions.publish_pages_done', [
                        'published' => $result['published'],
                        'skipped' => $result['skipped'],
                    ]));

                if (! empty($result['skipped_pages'])) {
                    $body = collect($result['skipped_pages'])
                        ->map(fn (array $row): string => sprintf(
                            '• %s — %s',
                            $row['name'],
                            __('capell-admin::bulk_actions.publish_pages_reason_' . $row['reason']),
                        ))
                        ->implode("\n");
                    $notification->body($body);
                }

                $result['published'] > 0 ? $notification->success() : $notification->warning();
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
