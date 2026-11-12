<?php

declare(strict_types=1);

namespace Capell\Core\Actions\FirstRun;

use Capell\Core\Data\FirstRun\GuidedSetupStateData;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Guided setup is owed to a site the actor can reach while nobody has set it up, skipped it,
 * imported a site spec into it or put its home page live. Anything else is an established site,
 * so an upgraded install is never sent to a setup screen.
 */
final class ResolveGuidedSetupStateAction
{
    use AsFake;
    use AsObject;

    public function handle(Authenticatable $actor): GuidedSetupStateData
    {
        $access = SiteAccess::forActor($actor);
        $site = $access->query(Site::class)->excludingPreview()->orderBy('id')->first();

        if (! $site instanceof Site) {
            return GuidedSetupStateData::notNeeded();
        }

        if ($this->isSetUpOrSkipped($site)) {
            return GuidedSetupStateData::notNeeded();
        }

        $homes = $access->query(Page::class)->homePage()->get();

        if ($homes->contains(fn (Page $home): bool => $home->publishVisibilityState() === PublishVisibilityStateEnum::published)) {
            return GuidedSetupStateData::notNeeded();
        }

        return GuidedSetupStateData::forSite($site);
    }

    /** The site whose pages guided setup created but the person has not published yet, so they can come back to publish. */
    public function awaitingPublication(Authenticatable $actor): ?Site
    {
        $access = SiteAccess::forActor($actor);
        $site = $access->query(Site::class)->excludingPreview()->orderBy('id')->first();

        if (! $site instanceof Site || blank(data_get($site->meta, 'guided_setup.completed_at'))) {
            return null;
        }

        $live = $access->query(Page::class)->homePage()->get()
            ->contains(fn (Page $home): bool => $home->publishVisibilityState() === PublishVisibilityStateEnum::published);

        return $live ? null : $site;
    }

    private function isSetUpOrSkipped(Site $site): bool
    {
        $meta = $site->meta ?? [];

        return filled(data_get($meta, 'guided_setup.completed_at'))
            || filled(data_get($meta, 'guided_setup.skipped_at'))
            || filled($meta['site_spec_hash'] ?? null)
            || filled($meta['site_spec_applied_hash'] ?? null);
    }
}
