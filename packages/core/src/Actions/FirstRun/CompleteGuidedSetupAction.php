<?php

declare(strict_types=1);

namespace Capell\Core\Actions\FirstRun;

use Capell\Core\Settings\FirstRunSettings;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\LaravelSettings\Exceptions\MissingSettings;

/**
 * Records that guided setup finished, with the business type and preset it
 * used. The first completion time is kept so a second run never moves it.
 */
final class CompleteGuidedSetupAction
{
    use AsFake;
    use AsObject;

    /** @return bool whether this call recorded the completion */
    public function handle(?string $businessType = null, ?string $presetKey = null, ?CarbonImmutable $at = null): bool
    {
        try {
            $settings = resolve(FirstRunSettings::class);
            if ($settings->setup_completed_at instanceof CarbonImmutable) {
                return false;
            }

            $settings->setup_completed_at = $at ?? CarbonImmutable::now();
            $settings->business_type = $this->clean($businessType) ?? $settings->business_type;
            $settings->preset_key = $this->clean($presetKey) ?? $settings->preset_key;
            $settings->save();
        } catch (MissingSettings) {
            return false;
        }

        return true;
    }

    private function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
