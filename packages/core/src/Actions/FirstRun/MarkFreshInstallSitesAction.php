<?php

declare(strict_types=1);

namespace Capell\Core\Actions\FirstRun;

use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\FirstRun\GuidedSetupStateMemo;
use Capell\Core\Support\FirstRun\SitePublicationMilestones;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Marks the sites a first install without sample content has just made, once the install has
 * finished and every package has set itself up, so guided setup is offered to them and only to
 * them. The mark records which home page the install left and a hash of its copy. A site
 * without the mark, including every site installed before it existed, is never offered setup.
 */
final class MarkFreshInstallSitesAction
{
    use AsFake;
    use AsObject;

    public function handle(): void
    {
        $milestones = resolve(SitePublicationMilestones::class);

        foreach (Site::query()->orderBy('id')->get() as $site) {
            if (ResolveGuidedSetupStateAction::isFreshInstall($site)) {
                continue;
            }

            if ($milestones->hasPublished((int) $site->getKey())) {
                continue;
            }

            $home = Page::query()->where('site_id', $site->getKey())->homePage()->orderBy('id')->first();

            // A site without a home page has nothing setup can build on.
            if (! $home instanceof Page) {
                continue;
            }

            $meta = is_array($site->meta) ? $site->meta : [];
            // The evidence of what the install left, so nothing later has to guess whether it is untouched.
            data_set($meta, ResolveGuidedSetupStateAction::FRESH_INSTALL_META_KEY, [
                'at' => now()->toIso8601String(),
                'home_uuid' => (string) $home->uuid,
                'home_hash' => ResolveGuidedSetupStateAction::homeHash($home),
            ]);
            $site->forceFill(['meta' => $meta])->save();
        }

        resolve(GuidedSetupStateMemo::class)->flush();
    }
}
