<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Turns the machine status recorded for the install's first page into the sentence a
 * person reads in the install summary. The status word itself stays the stable value in
 * the handoff JSON; it is never shown to people. `$hasAdmin` is whether an Admin panel is
 * installed: without one, an unverified page has no edit link to wait for.
 *
 * Translation keys stay literal so the language-key drift check can see every one.
 *
 * @method static string run(string $status, bool $hasAdmin = true)
 */
final class DescribeInstallFirstPageStatusAction
{
    use AsFake;
    use AsObject;

    public function handle(string $status, bool $hasAdmin = true): string
    {
        return match ($status) {
            'editable' => __('capell-core::install.handoff.summary.first_page.editable'),
            'present_unverified' => $hasAdmin
                ? __('capell-core::install.handoff.summary.first_page.present_unverified')
                : __('capell-core::install.handoff.summary.first_page.present_unverified_no_admin'),
            'draft' => __('capell-core::install.handoff.summary.first_page.draft'),
            'missing' => __('capell-core::install.handoff.summary.first_page.missing'),
            default => __('capell-core::install.handoff.summary.first_page.unavailable'),
        };
    }
}
