<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install\Cli;

final class FreshInstallDefaults
{
    /** @var list<string> */
    private const array ExplicitDemoInputOptions = [
        'url',
        'user',
        'name',
        'email',
        'password',
        'theme',
    ];

    /**
     * @param  array<string, mixed>  $optionValues
     */
    public static function hasExplicitDemoInput(array $optionValues): bool
    {
        foreach (self::ExplicitDemoInputOptions as $optionName) {
            if (! in_array($optionValues[$optionName] ?? null, [null, false, ''], true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function demoLanguages(string $applicationLocale): array
    {
        return array_values(array_unique([
            'en',
            $applicationLocale,
            'fr',
            'de',
        ]));
    }

    /** @return list<string> */
    public static function demoSites(string $applicationName): array
    {
        return [
            $applicationName,
            __('capell-core::install.demo.knowledge_site'),
            __('capell-core::install.demo.services_site'),
        ];
    }
}
