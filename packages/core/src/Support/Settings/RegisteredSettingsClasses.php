<?php

declare(strict_types=1);

namespace Capell\Core\Support\Settings;

use Capell\Core\Support\Bootstrap\SettingsBootstrapper;
use Spatie\LaravelSettings\Settings;

/**
 * The settings classes Spatie Laravel Settings loads, keyed by their group: the configured
 * classes, Capell's own and every installed package's.
 */
final class RegisteredSettingsClasses
{
    /**
     * @return array<string, class-string<Settings>>
     */
    public static function byGroup(): array
    {
        $classes = [];

        foreach (resolve(SettingsBootstrapper::class)->settingsClasses() as $class) {
            if (is_subclass_of($class, Settings::class)) {
                $classes[$class::group()] = $class;
            }
        }

        ksort($classes);

        return $classes;
    }
}
