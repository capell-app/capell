<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Pages\Actions;

use Capell\Admin\Actions\Pages\BulkRevertPagesToDraftAction;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;
use Override;

class BulkRevertToDraftBulkAction extends BulkAction
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::bulk_actions.revert_to_draft'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('capell-admin::bulk_actions.revert_to_draft_heading'))
            ->modalDescription(__('capell-admin::bulk_actions.revert_to_draft_description'))
            ->action(function (Collection $records): void {
                /** @var User $actor */
                $actor = auth()->user();

                $result = BulkRevertPagesToDraftAction::make()->handle($records, $actor);

                $notification = Notification::make()
                    ->title(__('capell-admin::bulk_actions.revert_to_draft_done', [
                        'reverted' => $result['reverted'],
                        'skipped' => $result['skipped'],
                    ]));

                if (! empty($result['skipped_pages'])) {
                    $body = collect($result['skipped_pages'])
                        ->map(fn (array $row): string => sprintf(
                            '• %s — %s',
                            $row['name'],
                            __('capell-admin::bulk_actions.revert_to_draft_reason_' . $row['reason']),
                        ))
                        ->implode("\n");
                    $notification->body($body);
                }

                $result['reverted'] > 0 ? $notification->success() : $notification->warning();
                $notification->send();
            });
    }

    public static function getDefaultName(): ?string
    {
        return 'bulk-revert-to-draft';
    }
}
