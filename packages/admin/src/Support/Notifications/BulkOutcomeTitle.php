<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Notifications;

/** Builds the one-line result of a bulk action with correct singular and plural wording. */
final class BulkOutcomeTitle
{
    /** @param  string  $done  The already translated outcome, for example from trans_choice(). */
    public static function make(string $done, int $skipped): string
    {
        if ($skipped <= 0) {
            return $done;
        }

        return $done . ' ' . trans_choice('capell-admin::bulk_actions.skipped_suffix', $skipped, ['count' => $skipped]);
    }
}
