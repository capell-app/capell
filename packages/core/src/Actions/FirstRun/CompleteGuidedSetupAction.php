<?php

declare(strict_types=1);

namespace Capell\Core\Actions\FirstRun;

use Capell\Core\Actions\ApplySiteSpecToSiteAction;
use Capell\Core\Actions\PlanSiteSpecAction;
use Capell\Core\Data\FirstRun\GuidedSetupInputData;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Data\SiteSpec\SiteSpecPlanData;
use Capell\Core\Exceptions\GuidedSetupRefusedException;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\FirstRun\GuidedSetupLogo;
use Capell\Core\Support\FirstRun\GuidedSetupStateMemo;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Creates the pages a person chose in guided setup and records that setup is done. The pages are
 * written by the SiteSpec apply path and stay private until the site is published.
 *
 * It writes only to a site that is still exactly as the installer left it. The install's public
 * placeholder home page stays as it is until the person publishes, so the site never shows nothing
 * at its address; the pages setup adds are private drafts. The site row is locked
 * and checked inside the transaction before anything is applied, so a repeated or concurrent
 * submit, or a request aimed at a live or half-built site, is refused instead of overwriting it.
 */
final class CompleteGuidedSetupAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  string|null  $logo  The uploaded logo's path on the guided setup logo disk; it is attached when the site is published
     *
     * @throws GuidedSetupRefusedException when guided setup is not owed to the site or it already holds work
     */
    public function handle(GuidedSetupInputData $input, Site $site, ?string $logo = null): SiteSpecPlanData
    {
        $spec = BuildGuidedSetupSpecAction::run($input, $site);

        $plan = DB::transaction(function () use ($input, $site, $spec, $logo): SiteSpecPlanData {
            $locked = Site::query()->lockForUpdate()->whereKey($site->getKey())->firstOrFail();
            $refusal = ResolveGuidedSetupStateAction::refusalFor($locked);

            throw_if($refusal === ResolveGuidedSetupStateAction::REASON_SET_UP, GuidedSetupRefusedException::alreadySetUp((int) $locked->getKey()));
            throw_if($refusal === ResolveGuidedSetupStateAction::REASON_EXISTING_WORK, GuidedSetupRefusedException::hasExistingWork((int) $locked->getKey()));

            $plan = ApplySiteSpecToSiteAction::run($spec, $locked);

            $locked->update(['name' => $spec->site->name]);

            // Re-read after the apply, which writes its own meta, so nothing recorded alongside is lost.
            $fresh = Site::query()->whereKey($locked->getKey())->firstOrFail();
            $meta = is_array($fresh->meta) ? $fresh->meta : [];
            $meta['guided_setup'] = [
                ...(array) data_get($meta, 'guided_setup', []),
                'preset_key' => $input->presetKey,
                'page_slugs' => $input->pageSlugs,
                'tagline' => $input->tagline,
                'theme_key' => $input->themeKey,
                'primary_colour' => $input->primaryColour,
                // Held privately until the person publishes: the logo must not reach a live site before then.
                'logo' => GuidedSetupLogo::path($logo) !== null ? $logo : null,
                // Publish works from what was created, whatever happens to the preset afterwards.
                'spec' => $spec->toArray(),
                'page_uuids' => $this->createdDrafts($spec, $fresh),
                'completed_at' => now()->toIso8601String(),
            ];
            $fresh->forceFill(['meta' => $meta])->save();
            $site->setRawAttributes($fresh->getAttributes(), true);

            return $plan;
        });

        resolve(GuidedSetupStateMemo::class)->flush();

        return $plan;
    }

    /**
     * The pages this setup created, still private drafts; the install's own home page is not one of them.
     *
     * @return list<string>
     */
    private function createdDrafts(CapellSiteSpecData $spec, Site $site): array
    {
        $uuids = [];

        foreach (PlanSiteSpecAction::make()->existingPagesBySlug($spec, $site) as $found) {
            $page = $found['page'];

            if (ResolveGuidedSetupStateAction::isPrivateDraft($page) && ! Page::query()->homePage()->whereKey($page->getKey())->exists()) {
                $uuids[] = (string) $page->uuid;
            }
        }

        return $uuids;
    }
}
