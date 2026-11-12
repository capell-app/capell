<?php

declare(strict_types=1);

namespace Capell\Core\Actions\FirstRun;

use Capell\Core\Models\Site;
use Capell\Core\Support\FirstRun\GuidedSetupStateMemo;
use Capell\Core\Support\FirstRun\SitePublicationMilestones;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Marks the sites a first install has just made, once the install has finished and every
 * package has set itself up, so guided setup is offered to them and only to them. A site
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

            $meta = is_array($site->meta) ? $site->meta : [];
            data_set($meta, ResolveGuidedSetupStateAction::FRESH_INSTALL_META_KEY, now()->toIso8601String());
            $site->forceFill(['meta' => $meta])->save();
        }

        resolve(GuidedSetupStateMemo::class)->flush();
    }
}
