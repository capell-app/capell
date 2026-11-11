<?php

declare(strict_types=1);

namespace Capell\Core\Actions\FirstRun;

use Capell\Core\Settings\FirstRunSettings;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\LaravelSettings\Exceptions\MissingSettings;

/**
 * Records the first time this install's site went public. The first value is
 * kept, so unpublishing and republishing a page never resets the milestone.
 */
final class RecordSitePublishedAction
{
    use AsFake;
    use AsObject;

    /** @return bool whether this call recorded the milestone */
    public function handle(?CarbonImmutable $at = null): bool
    {
        try {
            $settings = resolve(FirstRunSettings::class);
            if ($settings->published_at instanceof CarbonImmutable) {
                return false;
            }

            $settings->published_at = $at ?? CarbonImmutable::now();
            $settings->save();
        } catch (MissingSettings) {
            // The settings migration has not run yet; publishing must not fail because of it.
            return false;
        }

        return true;
    }
}
