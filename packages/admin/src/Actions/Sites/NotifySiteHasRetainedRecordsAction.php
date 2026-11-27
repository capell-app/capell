<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Sites;

use Capell\Core\Exceptions\SiteHasRetainedRecordsException;
use Filament\Notifications\Notification;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Explain that a permanent site deletion was refused because a package must keep some of the site's records.
 *
 * @method static void run(SiteHasRetainedRecordsException $exception, bool $bulk = false)
 */
final class NotifySiteHasRetainedRecordsAction
{
    use AsFake;
    use AsObject;

    public function handle(SiteHasRetainedRecordsException $exception, bool $bulk = false): void
    {
        Notification::make('site_has_retained_records')
            ->danger()
            ->title(__('capell-admin::message.site_force_delete_retained_title'))
            ->body(__($bulk
                ? 'capell-admin::message.site_force_delete_retained_bulk_body'
                : 'capell-admin::message.site_force_delete_retained_body', ['reason' => $exception->getMessage()]))
            ->persistent()
            ->send();
    }
}
