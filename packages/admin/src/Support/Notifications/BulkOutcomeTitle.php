<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Notifications;

/** Builds the one-line result of a bulk action with correct singular and plural wording. */
final class BulkOutcomeTitle
{
    /** @param  string  $doneKey  A plural-aware key under capell-admin::bulk_actions that receives :count. */
    public static function make(string $doneKey, int $done, int $skipped): string
    {
        $title = trans_choice('capell-admin::bulk_actions.' . $doneKey, $done, ['count' => $done]);

        if ($skipped > 0) {
            $title .= ' ' . trans_choice('capell-admin::bulk_actions.skipped_suffix', $skipped, ['count' => $skipped]);
        }

        return $title;
    }
}
