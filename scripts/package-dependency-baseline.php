<?php

declare(strict_types=1);

/**
 * Accepted debt for scripts/check-package-dependencies.php.
 *
 * Entries are expected to shrink: declare the dependency in the owning
 * packages/<package>/composer.json, or stop using the vendor there.
 *
 * To review current debt, run:
 *   php scripts/check-package-dependencies.php --print-baseline
 * This command only prints a candidate. It never writes this file.
 *
 * Keys are Capell package directory names. Sections are analyser error types.
 * A new violation must be added here explicitly, and stale entries fail
 * through the analyser's unmatched-ignore reporting.
 */
return [
    'admin' => [
        'shadow' => [
            'aimeos/laravel-nestedset',
            'filament/actions',
            'filament/forms',
            'filament/infolists',
            'filament/notifications',
            'filament/schemas',
            'filament/support',
            'filament/tables',
            'filament/widgets',
            'livewire/livewire',
            'nesbot/carbon',
            'nikic/php-parser',
            'pboivin/filament-peek',
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
        'unused' => [
            'capell-app/core',
            'guzzlehttp/guzzle',
            'laravel/prompts',
            'saade/filament-adjacency-list',
        ],
        'unknownClasses' => [
            '~^Znck\\\\Eloquent\\\\Relations\\\\BelongsToThrough$~',
        ],
    ],
    'core' => [
        'shadow' => [
            'composer/semver',
            'fakerphp/faker',
            'filament/filament',
            'filament/forms',
            'filament/spatie-laravel-media-library-plugin',
            'laravel/framework',
            'laravel/octane',
            'livewire/livewire',
            'nesbot/carbon',
            'nikic/php-parser',
            'ramsey/uuid',
            'spatie/image',
            'spatie/laravel-package-tools',
            'symfony/console',
            'symfony/finder',
            'symfony/http-foundation',
        ],
        'unused' => [
            'illuminate/database',
            'illuminate/support',
            'spatie/laravel-tags',
            'symfony/filesystem',
        ],
    ],
    'frontend' => [
        'shadow' => [
            'aimeos/laravel-nestedset',
            'filament/filament',
            'filament/forms',
            'filament/schemas',
            'filament/support',
            'livewire/blaze',
            'nesbot/carbon',
            'psr/log',
            'spatie/laravel-data',
            'spatie/laravel-package-tools',
            'spatie/laravel-settings',
            'symfony/filesystem',
            'symfony/http-foundation',
            'symfony/http-kernel',
        ],
        'unused' => [
            'blade-ui-kit/blade-heroicons',
            'capell-app/core',
            'guzzlehttp/guzzle',
            'intervention/image',
            'michaloravec/laravel-paginateroute',
            'spatie/laravel-permission',
            'spatie/laravel-translatable',
        ],
    ],
    'installer' => [
        'shadow' => [
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
        'unused' => [
            'capell-app/core',
        ],
    ],
    'marketplace' => [
        'shadow' => [
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
        'unused' => [
            'capell-app/admin',
            'capell-app/core',
        ],
    ],
];
