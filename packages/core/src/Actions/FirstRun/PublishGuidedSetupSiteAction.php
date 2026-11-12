<?php

declare(strict_types=1);

namespace Capell\Core\Actions\FirstRun;

use Capell\Core\Actions\PublishSiteSpecSiteAction;
use Capell\Core\Data\FirstRun\GuidedSetupInputData;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Exceptions\GuidedSetupRefusedException;
use Capell\Core\Exceptions\SiteSpecPublishRefusedException;
use Capell\Core\Models\Site;
use Capell\Core\Support\FirstRun\GuidedSetupLogo;
use Capell\Core\Support\FirstRun\GuidedSetupStateMemo;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Publishes what guided setup created, once and all or nothing. The site row is locked and
 * re-checked inside the transaction, so a repeated or concurrent request finds the site already
 * published and changes nothing. Publishing the spec writes the person's home page over the
 * install's placeholder and records the go-live; the logo held back since setup is attached
 * only after the transaction commits, by the request that published.
 */
final class PublishGuidedSetupSiteAction
{
    use AsFake;
    use AsObject;

    /**
     * @throws SiteSpecPublishRefusedException when a page cannot be published; nothing is changed
     * @throws GuidedSetupRefusedException when the site has no setup waiting to be published
     */
    public function handle(GuidedSetupInputData $input, Site $site, Authenticatable $actor): int
    {
        $spec = BuildGuidedSetupSpecAction::run($input, $site);

        $published = DB::transaction(function () use ($spec, $site, $actor): int {
            $locked = Site::query()->lockForUpdate()->whereKey($site->getKey())->firstOrFail();

            throw_if(
                blank(data_get($locked->meta, 'guided_setup.completed_at')) || filled(data_get($locked->meta, 'guided_setup.published_at')),
                GuidedSetupRefusedException::alreadySetUp((int) $locked->getKey()),
            );

            $published = PublishSiteSpecSiteAction::run($spec, $locked, $actor);

            $meta = is_array($locked->refresh()->meta) ? $locked->meta : [];
            data_set($meta, 'guided_setup.published_at', now()->toIso8601String());
            $locked->forceFill(['meta' => $meta])->save();

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
