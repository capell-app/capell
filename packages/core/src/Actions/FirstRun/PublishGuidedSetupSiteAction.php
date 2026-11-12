<?php

declare(strict_types=1);

namespace Capell\Core\Actions\FirstRun;

use Capell\Core\Actions\PlanSiteSpecAction;
use Capell\Core\Actions\PublishSiteSpecSiteAction;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Exceptions\GuidedSetupLogoNotAttachedException;
use Capell\Core\Exceptions\GuidedSetupRefusedException;
use Capell\Core\Exceptions\SiteSpecPublishRefusedException;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\FirstRun\GuidedSetupStateMemo;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Publishes what guided setup created and nobody has touched since, once and all or nothing, from
 * the spec stored when the pages were created, so a preset changed or removed since makes no
 * difference. The home page is written and published only while it is still the install's
 * placeholder; each created page only while it is still a private draft no person has edited,
 * published, scheduled, reverted or deleted. Everything else is left exactly as the person has it.
 * The site row is locked and re-checked inside the transaction, so a repeated or concurrent request
 * changes nothing. The logo held back since setup is attached after the transaction commits; a
 * failure to attach it leaves the site published and the logo file kept, and is reported.
 */
final class PublishGuidedSetupSiteAction
{
    use AsFake;
    use AsObject;

    /**
     * @throws SiteSpecPublishRefusedException when a page cannot be published; nothing is changed
     * @throws GuidedSetupRefusedException when the site has nothing waiting to be published
     * @throws GuidedSetupLogoNotAttachedException when the site was published but the logo could not be attached
     */
    public function handle(Site $site, Authenticatable $actor): int
    {
        $published = DB::transaction(function () use ($site, $actor): int {
            $locked = Site::query()->lockForUpdate()->whereKey($site->getKey())->firstOrFail();

            throw_unless(ResolveGuidedSetupStateAction::awaitsPublication($locked), GuidedSetupRefusedException::alreadySetUp((int) $locked->getKey()));

            /** @var array<string, mixed> $stored */
            $stored = data_get($locked->meta, 'guided_setup.spec');
            $spec = CapellSiteSpecData::from($stored);

            $published = PublishSiteSpecSiteAction::run($spec, $locked, $actor, $this->untouchedSlugs($spec, $locked));

            $meta = is_array($locked->refresh()->meta) ? $locked->meta : [];
            data_set($meta, 'guided_setup.published_at', now()->toIso8601String());
            $locked->forceFill(['meta' => $meta])->save();

            // Setup's Publish is a go-live whenever it leaves a public home page, even one the person wrote.
            if (Page::query()->where('site_id', $locked->getKey())->homePage()->published()->exists()) {
                RecordSitePublishedAction::run((int) $locked->getKey(), CarbonImmutable::now());
            }

            return $published;
        });

        resolve(GuidedSetupStateMemo::class)->flush();

        try {
            AttachGuidedSetupLogoAction::run($site->refresh());
        } catch (Throwable $throwable) {
            report($throwable);

            throw GuidedSetupLogoNotAttachedException::forSite((int) $site->getKey(), $throwable);
        }

        return $published;
    }

    /**
     * The spec pages setup may still publish: the home page while it is the placeholder, and each
     * created page nobody has touched.
     *
     * @return list<string>
     */
    private function untouchedSlugs(CapellSiteSpecData $spec, Site $site): array
    {
        $home = ResolveGuidedSetupStateAction::placeholderHome($site);
        $untouched = array_map(static fn (Page $page): int => (int) $page->getKey(), ResolveGuidedSetupStateAction::untouchedSetupPages($site));

        if ($home instanceof Page) {
            $untouched[] = (int) $home->getKey();
        }

        $slugs = [];

        foreach (PlanSiteSpecAction::make()->existingPagesBySlug($spec, $site) as $slug => $found) {
            if (in_array((int) $found['page']->getKey(), $untouched, true)) {
                $slugs[] = (string) $slug;
            }
        }

        return $slugs;
    }
}
