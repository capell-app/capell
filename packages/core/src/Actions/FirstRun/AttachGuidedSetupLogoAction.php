<?php

declare(strict_types=1);

namespace Capell\Core\Actions\FirstRun;

use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Models\Site;
use Capell\Core\Support\FirstRun\GuidedSetupLogo;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Attaches the logo guided setup held back until its Publish, then forgets it. Only setup's
 * Publish and the person's explicit retry call this. The site row is locked and the logo re-read
 * inside the lock, so two requests never attach it twice. The media library removes the file only
 * once it is attached, so a failure keeps the file and its record for another try.
 */
final class AttachGuidedSetupLogoAction
{
    use AsFake;
    use AsObject;

    /** Whether setup's Publish has run and a held logo is still waiting to be attached. */
    public static function pending(Site $site): bool
    {
        return filled(data_get($site->meta, 'guided_setup.published_at')) && filled(data_get($site->meta, 'guided_setup.logo'));
    }

    public function handle(Site $site): void
    {
        DB::transaction(function () use ($site): void {
            $locked = Site::query()->lockForUpdate()->whereKey($site->getKey())->first();

            if (! $locked instanceof Site || ! self::pending($locked)) {
                return;
            }

            $stored = data_get($locked->meta, 'guided_setup.logo');
            $path = GuidedSetupLogo::path(is_string($stored) ? $stored : null);

            if ($path !== null) {
                $locked->addMedia($path)->toMediaCollection(MediaCollectionEnum::Logo->value);
            }

            $meta = is_array($locked->meta) ? $locked->meta : [];
            data_set($meta, 'guided_setup.logo', null);
            $locked->forceFill(['meta' => $meta])->save();
        });

        $site->refresh();
    }
}
