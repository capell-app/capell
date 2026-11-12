<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Capell\Core\Models\Page;
use Capell\Core\Support\Publishing\PublishSentinel;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Puts the prepared install back into the state a site is in before its first
 * publish, so the dashboard shows the first-run checklist.
 *
 * The demo install publishes the site, and the checklist only appears while no
 * accessible site has a public home page. This changes shared data, so the
 * capture hook only runs it for a run made up solely of first-run entries.
 */
final class FirstRunDashboardScreenshotFixture
{
    public static function initialize(): void
    {
        $homes = Page::query()->homePage()->get();

        throw_if($homes->isEmpty(), RuntimeException::class, 'The first-run dashboard capture needs a prepared install with a home page.');

        foreach ($homes as $home) {
            $home->forceFill(['visible_from' => PublishSentinel::draftValue(CarbonImmutable::now())])->saveQuietly();
        }
    }
}
