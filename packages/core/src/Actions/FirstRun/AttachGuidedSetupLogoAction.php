<?php

declare(strict_types=1);

namespace Capell\Core\Actions\FirstRun;

use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Models\Site;
use Capell\Core\Support\FirstRun\GuidedSetupLogo;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Attaches the logo guided setup held back until the site was published, then forgets it.
 * The media library removes the file only once it is attached, so a failure keeps the file and
 * its record and the same call can be made again.
 */
final class AttachGuidedSetupLogoAction
{
    use AsFake;
    use AsObject;

    /** Whether a held logo is still waiting to be attached to the published site. */
    public static function pending(Site $site): bool
    {
        return filled(data_get($site->meta, 'guided_setup.published_at')) && filled(data_get($site->meta, 'guided_setup.logo'));
    }

    public function handle(Site $site): void
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
