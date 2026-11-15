<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Capell\Core\Actions\FirstRun\ResolveGuidedSetupStateAction;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Publishing\PublishSentinel;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Puts the prepared install back into the state a site is in straight after a first install, before guided setup has
 * run, so the setup page offers its form. The demo install publishes the site, so this undoes that: it changes shared
 * data, and the capture hook only runs it for a run made up solely of the guided-setup entry.
 */
final class GuidedSetupScreenshotFixture
{
    public static function initialize(): void
    {
        $site = Site::query()->orderBy('id')->first();
        throw_unless($site instanceof Site, RuntimeException::class, 'The guided setup capture needs a prepared install with a site.');

        $home = Page::query()->where('site_id', $site->getKey())->homePage()->first();
        throw_unless($home instanceof Page, RuntimeException::class, 'The guided setup capture needs a prepared install with a home page.');

        $home->forceFill(['visible_from' => PublishSentinel::draftValue(CarbonImmutable::now())])->saveQuietly();

        $meta = is_array($site->meta) ? $site->meta : [];
        unset($meta['first_run']['published_at'], $meta['guided_setup'], $meta['site_spec_hash'], $meta['site_spec_applied_hash']);
        $meta[ResolveGuidedSetupStateAction::FRESH_INSTALL_META_KEY] = [
            'home_uuid' => (string) $home->uuid,
            'home_copy' => ResolveGuidedSetupStateAction::homeCopyStamp($home),
        ];
        $site->forceFill(['meta' => $meta])->saveQuietly();

        $refusal = ResolveGuidedSetupStateAction::refusalFor($site->refresh());
        throw_if($refusal !== null, RuntimeException::class, 'The guided setup capture could not put the site into its owed state: ' . $refusal . '.');
    }
}
