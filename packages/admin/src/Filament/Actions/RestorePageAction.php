<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions;

use Capell\Admin\Actions\RestorePageCascadeAction;
use Capell\Core\Models\Page;
use Filament\Actions\RestoreAction;
use Override;

final class RestorePageAction extends RestoreAction
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->using(fn (Page $record): bool => RestorePageCascadeAction::run($record));
        $this->failureNotificationTitle(__('capell-admin::message.recently_deleted_restore_cascade_denied'));
    }
}
