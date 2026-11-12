<?php

declare(strict_types=1);

namespace Capell\Core\Actions\FirstRun;

use Capell\Core\Actions\PlanSiteSpecAction;
use Capell\Core\Actions\PublishSiteSpecSiteAction;
use Capell\Core\Data\FirstRun\GuidedSetupInputData;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Exceptions\SiteSpecPublishRefusedException;
use Capell\Core\Models\Site;
use Capell\Core\Support\FirstRun\GuidedSetupLogo;
use Capell\Core\Support\FirstRun\GuidedSetupStateMemo;
use Capell\Core\Support\SiteSpec\SiteSpecPageContent;
use Capell\Core\Support\SiteSpec\SiteSpecPageSlug;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Publishes what guided setup created, all or nothing: the person's home page is written over
 * the install's placeholder home page (unless someone has edited it since), every page setup
 * created is made public, the site's go-live is recorded and, last, the logo held back since
 * setup is attached to the now-live site.
 */
final class PublishGuidedSetupSiteAction
{
    use AsFake;
    use AsObject;

    /** @throws SiteSpecPublishRefusedException when a page cannot be published; nothing is changed */
    public function handle(GuidedSetupInputData $input, Site $site, Authenticatable $actor): int
    {
        $spec = BuildGuidedSetupSpecAction::run($input, $site);

        $published = DB::transaction(function () use ($spec, $site, $actor): int {
            $homeSpec = $spec->pages[0] ?? null;
            $home = $homeSpec === null ? null : (PlanSiteSpecAction::make()->existingPagesBySlug($spec, $site)[SiteSpecPageSlug::for($homeSpec)] ?? null);

            if ($homeSpec !== null && $home !== null && ResolveGuidedSetupStateAction::isPlaceholderHome($site, $home['page'])) {
                $home['page']->update(['name' => $homeSpec->name]);
                $home['translation']->update(['title' => $homeSpec->title, 'content' => SiteSpecPageContent::html($homeSpec)]);
            }

            $published = PublishSiteSpecSiteAction::run($spec, $site, $actor);
            RecordSitePublishedAction::run((int) $site->getKey());

            return $published;
        });

        resolve(GuidedSetupStateMemo::class)->flush();

        $this->attachLogo($site->refresh());

        return $published;
    }

    private function attachLogo(Site $site): void
    {
        $stored = data_get($site->meta, 'guided_setup.logo');
        $path = GuidedSetupLogo::path(is_string($stored) ? $stored : null);

        if ($path !== null) {
            $site->addMedia($path)->toMediaCollection(MediaCollectionEnum::Logo->value);
        }

        if ($stored !== null) {
            $meta = is_array($site->meta) ? $site->meta : [];
            data_set($meta, 'guided_setup.logo', null);
            $site->forceFill(['meta' => $meta])->save();
        }
    }
}
