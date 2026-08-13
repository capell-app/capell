<?php

declare(strict_types=1);

/**
 * Accepted debt for scripts/check-package-dependencies.php.
 *
 * Each entry silences one Composer package for one Capell package. Entries are
 * expected to shrink: the fix is to declare the dependency in the owning
 * packages/<package>/composer.json, or to stop using the vendor there.
 *
 * Regenerate with: php scripts/check-package-dependencies.php --update
 *
 * Keys are Capell package directory names. Shape per entry:
 *   'prod' => Composer package names to ignore entirely
 *   'unknownClasses' => PCRE patterns for classes the analyser cannot autoload
 */
return [
    'admin' => [
        'prod' => [
            'aimeos/laravel-nestedset',
            'filament/actions',
            'filament/forms',
            'filament/infolists',
            'filament/notifications',
            'filament/schemas',
            'filament/support',
            'filament/tables',
            'filament/widgets',
            'guzzlehttp/guzzle',
            'livewire/livewire',
            'nesbot/carbon',
            'nikic/php-parser',
            'pboivin/filament-peek',
            'saade/filament-adjacency-list',
            'spatie/laravel-activitylog',
            'spatie/laravel-data',
            'spatie/laravel-medialibrary',
            'spatie/laravel-permission',
            'spatie/laravel-settings',
            'symfony/finder',
            'symfony/http-foundation',
            'symfony/http-kernel',
            'symfony/routing',
        ],
        'unknownClasses' => [
            '~^Znck\\\\Eloquent\\\\Relations\\\\BelongsToThrough$~',
        ],
    ],
    'core' => [
        'prod' => [
            'composer/semver',
            'fakerphp/faker',
            'filament/filament',
            'filament/forms',
            'filament/spatie-laravel-media-library-plugin',
            'illuminate/database',
            'illuminate/support',
            'laravel/framework',
            'laravel/octane',
            'laravel/prompts',
            'livewire/livewire',
            'nesbot/carbon',
            'nikic/php-parser',
            'orchestra/testbench-core',
            'ramsey/uuid',
            'spatie/image',
            'spatie/laravel-package-tools',
            'spatie/laravel-tags',
            'symfony/console',
            'symfony/filesystem',
            'symfony/finder',
            'symfony/http-foundation',
        ],
    ],
    'frontend' => [
        'prod' => [
            'aimeos/laravel-nestedset',
            'blade-ui-kit/blade-heroicons',
            'filament/filament',
            'filament/forms',
            'filament/schemas',
            'filament/support',
            'guzzlehttp/guzzle',
            'intervention/image',
            'laravel/prompts',
            'livewire/blaze',
            'michaloravec/laravel-paginateroute',
            'nesbot/carbon',
            'psr/log',
            'spatie/laravel-data',
            'spatie/laravel-package-tools',
            'spatie/laravel-permission',
            'spatie/laravel-settings',
            'spatie/laravel-translatable',
            'symfony/filesystem',
            'symfony/http-foundation',
            'symfony/http-kernel',
        ],
    ],
    'installer' => [
        'prod' => [
            'bezhansalleh/filament-shield',
            'filament/actions',
            'filament/filament',
            'filament/forms',
            'filament/notifications',
            'filament/support',
            'filament/widgets',
            'laravel/framework',
            'livewire/livewire',
            'lorisleiva/laravel-actions',
            'nikic/php-parser',
            'spatie/laravel-activitylog',
            'spatie/laravel-data',
            'spatie/laravel-permission',
            'symfony/http-foundation',
            'symfony/http-kernel',
            'symfony/process',
            'symfony/routing',
        ],
    ],
    'marketplace' => [
        'prod' => [
            'bezhansalleh/filament-shield',
            'composer/semver',
            'filament/actions',
            'filament/filament',
            'filament/forms',
            'filament/notifications',
            'filament/schemas',
            'filament/support',
            'filament/tables',
            'filament/widgets',
            'livewire/livewire',
            'nesbot/carbon',
            'symfony/console',
            'symfony/http-kernel',
            'symfony/process',
        ],
    ],
];
