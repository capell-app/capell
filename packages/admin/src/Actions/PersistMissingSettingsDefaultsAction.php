<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use ReflectionProperty;
use Spatie\LaravelSettings\Settings;
use Spatie\LaravelSettings\Support\Crypto;

final class PersistMissingSettingsDefaultsAction
{
    use AsFake;
    use AsObject;

    /**
     * Persist defaults for newly added settings before Spatie rejects saving them as missing.
     *
     * @param  class-string<Settings>  $settingsClass
     */
    public function handle(string $settingsClass): void
    {
        $settings = resolve($settingsClass);
        $config = $settings->settingsConfig();
        $repository = $config->getRepository();
        $group = $config->getGroup();
        $existingSettings = array_keys($repository->getPropertiesInGroup($group));

        $defaults = $config
            ->getReflectedProperties()
            ->reject(fn (ReflectionProperty $property, string $name): bool => in_array($name, $existingSettings, true))
            ->filter(fn (ReflectionProperty $property): bool => $property->hasDefaultValue())
            ->mapWithKeys(function (ReflectionProperty $property, string $name) use ($config): array {
                $payload = $property->getDefaultValue();

                if ($cast = $config->getCast($name)) {
                    $payload = $cast->set($payload);
                }

                if ($config->isEncrypted($name)) {
                    $payload = Crypto::encrypt($payload);
                }

                return [$name => $payload];
            })
            ->all();

        if ($defaults === []) {
            return;
        }

        $repository->updatePropertiesPayload($group, $defaults);
        $settings->refresh();
    }
}
