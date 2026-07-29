<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Settings\FrontendSettings;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\LaravelSettings\Support\SettingsCacheFactory;

class PersistScheduledPublicationInvalidationCheckpointAction
{
    use AsFake;
    use AsObject;

    public function handle(string $checkpoint): void
    {
        resolve(FrontendSettings::class)
            ->getRepository()
            ->updatePropertiesPayload(
                FrontendSettings::group(),
                ['scheduled_publication_invalidation_checkpoint' => $checkpoint],
            );

        $freshSettings = (new FrontendSettings)->refresh();
        resolve(SettingsCacheFactory::class)
            ->build(FrontendSettings::repository())
            ->put($freshSettings);

        app()->forgetInstance(FrontendSettings::class);
    }
}
