<?php

declare(strict_types=1);

namespace Capell\Core\Actions\FirstRun;

use Capell\Core\Actions\ApplySiteSpecToSiteAction;
use Capell\Core\Data\FirstRun\GuidedSetupInputData;
use Capell\Core\Data\SiteSpec\SiteSpecPlanData;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Models\Site;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Creates the pages a person chose in guided setup and records that setup is done. The pages are
 * written by the SiteSpec apply path and stay private until the site is published.
 */
final class CompleteGuidedSetupAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  string|null  $logoPath  A file on the local filesystem to use as the site logo
     */
    public function handle(GuidedSetupInputData $input, Site $site, ?string $logoPath = null): SiteSpecPlanData
    {
        $spec = BuildGuidedSetupSpecAction::run($input, $site);

        return DB::transaction(function () use ($input, $site, $spec, $logoPath): SiteSpecPlanData {
            $plan = ApplySiteSpecToSiteAction::run($spec, $site);

            $site->update(['name' => $spec->site->name]);

            if ($logoPath !== null && is_file($logoPath)) {
                $site->addMedia($logoPath)->preservingOriginal()->toMediaCollection(MediaCollectionEnum::Logo->value);
            }

            // Read the row under a lock, after the spec apply wrote its own meta, so nothing recorded alongside is lost.
            $fresh = Site::query()->lockForUpdate()->whereKey($site->getKey())->firstOrFail();
            $meta = is_array($fresh->meta) ? $fresh->meta : [];
            $meta['guided_setup'] = [
                ...(array) data_get($meta, 'guided_setup', []),
                'preset_key' => $input->presetKey,
                'completed_at' => now()->toIso8601String(),
            ];
            $fresh->forceFill(['meta' => $meta])->save();
            $site->setRawAttributes($fresh->getAttributes(), true);

            return $plan;
        });
    }
}
