<?php

declare(strict_types=1);

namespace Capell\Core\Actions\FirstRun;

use Capell\Core\Actions\PlanSiteSpecAction;
use Capell\Core\Actions\PublishSiteSpecSiteAction;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Data\SiteSpec\CapellSiteSpecPageData;
use Capell\Core\Exceptions\GuidedSetupLogoNotAttachedException;
use Capell\Core\Exceptions\GuidedSetupRefusedException;
use Capell\Core\Exceptions\SiteSpecPublishRefusedException;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\FirstRun\GuidedSetupStateMemo;
use Capell\Core\Support\SiteSpec\SiteSpecPageSlug;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Publishes what guided setup created, once and all or nothing, from the spec stored when the
 * pages were created, so a preset changed or removed since makes no difference. Only the home
 * page and the pages setup created that are still private drafts are published: a page the
 * person deleted is left deleted. The site row is locked and re-checked inside the transaction,
 * so a repeated or concurrent request, or a site the person has already taken public from the
 * editor, changes nothing. The logo held back since setup is attached after the transaction
 * commits, by the request that published; a failure to attach it leaves the site published and
 * the logo file kept, and is reported to the caller.
 */
final class PublishGuidedSetupSiteAction
{
    use AsFake;
    use AsObject;

    /**
     * @throws SiteSpecPublishRefusedException when a page cannot be published; nothing is changed
     * @throws GuidedSetupRefusedException when the site has no setup waiting to be published
     * @throws GuidedSetupLogoNotAttachedException when the site was published but the logo could not be attached
     */
    public function handle(Site $site, Authenticatable $actor): int
    {
        $published = DB::transaction(function () use ($site, $actor): ?int {
            $locked = Site::query()->lockForUpdate()->whereKey($site->getKey())->firstOrFail();
            $stored = data_get($locked->meta, 'guided_setup.spec');

            // Refused after the transaction, so a go-live recorded here because the person took the site public is kept.
            if (! SettleGuidedSetupPublicationAction::run($locked) || ! is_array($stored)) {
                return null;
            }

            $published = PublishSiteSpecSiteAction::run($this->stillPrivate(CapellSiteSpecData::from($stored), $locked), $locked, $actor);

            $meta = is_array($locked->refresh()->meta) ? $locked->meta : [];
            data_set($meta, 'guided_setup.published_at', now()->toIso8601String());
            $locked->forceFill(['meta' => $meta])->save();

            return $published;
        });

        resolve(GuidedSetupStateMemo::class)->flush();

        throw_if($published === null, GuidedSetupRefusedException::alreadySetUp((int) $site->getKey()));

        try {
            AttachGuidedSetupLogoAction::run($site->refresh());
        } catch (Throwable $throwable) {
            report($throwable);

            throw GuidedSetupLogoNotAttachedException::forSite((int) $site->getKey(), $throwable);
        }

        return $published;
    }

    /** The home page and each page setup created that is still a private draft; anything else stays as the person left it. */
    private function stillPrivate(CapellSiteSpecData $spec, Site $site): CapellSiteSpecData
    {
        $drafts = Page::query()->where('site_id', $site->getKey())->whereIn('uuid', SettleGuidedSetupPublicationAction::setupPageUuids($site))->get()
            ->filter(SettleGuidedSetupPublicationAction::isPrivateDraft(...))
            ->map(fn (Page $page): int => (int) $page->getKey())
            ->all();
        $existing = PlanSiteSpecAction::make()->existingPagesBySlug($spec, $site);

        $pages = array_values(array_filter(
            $spec->pages,
            static function (CapellSiteSpecPageData $page) use ($existing, $drafts): bool {
                if ($page->slug === BuildGuidedSetupSpecAction::HOME_SLUG) {
                    return true;
                }

                $found = $existing[SiteSpecPageSlug::for($page)]['page'] ?? null;

                return $found instanceof Page && in_array((int) $found->getKey(), $drafts, true);
            },
        ));

        return CapellSiteSpecData::from([...$spec->toArray(), 'pages' => array_map(static fn (CapellSiteSpecPageData $page): array => $page->toArray(), $pages)]);
    }
}
