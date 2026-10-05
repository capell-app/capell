<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions;

use Capell\Admin\Actions\RestorePageCascadeAction;
use Capell\Core\Models\Page;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Override;

final class RestorePageAction extends RestoreAction
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->using(function (Page $record): bool {
            $result = RestorePageCascadeAction::make()->restoreWithResult($record);
            if ($result->restored && $result->notice !== null) {
                $this->successNotification(Notification::make()
                    ->title(__('capell-admin::message.recently_deleted_restored'))
                    ->body($result->notice)->warning());
            }

            return $result->restored;
        });
        $this->failureNotificationTitle(__('capell-admin::message.recently_deleted_restore_cascade_denied'));
    }
}
