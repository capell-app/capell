<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Allow Reinstall
    |--------------------------------------------------------------------------
    |
    | Keep disabled in normal installs so revisiting /install shows an
    | installed notice instead of exposing the installer form again.
    |
    */
    'allow_reinstall' => env('CAPELL_SETUP_ALLOW_REINSTALL', env('APP_DEBUG', false)),

    /*
    |--------------------------------------------------------------------------
    | Composer Binary
    |--------------------------------------------------------------------------
    |
    | Override this when Composer is not available as "composer" to the web
    | process, such as Windows installs or hosts using composer.phar.
    |
    */
    'composer_binary' => env('CAPELL_SETUP_COMPOSER_BINARY', 'composer'),

    /*
    |--------------------------------------------------------------------------
    | PHP Binary
    |--------------------------------------------------------------------------
    |
    | Used when the installer needs a fresh Artisan process. Web SAPIs often
    | expose PHP_BINARY as php-fpm, so default to the CLI binary on PATH.
    |
    */
    'php_binary' => env('CAPELL_SETUP_PHP_BINARY', 'php'),

    /*
    |--------------------------------------------------------------------------
    | Default Packages
    |--------------------------------------------------------------------------
    |
    | Optional packages listed here are selected by default in the browser
    | installer when they are available. They remain normal extensions, so the
    | installer user can deselect them and admins can remove them later.
    |
    */
    'default_packages' => array_values(array_filter(
        array_map(
            trim(...),
            explode(',', env('CAPELL_SETUP_DEFAULT_PACKAGES', 'capell-app/filamentors') ?? ''),
        ),
        static fn (string $packageName): bool => $packageName !== '',
    )),

    /*
    |--------------------------------------------------------------------------
    | Default Admin User
    |--------------------------------------------------------------------------
    |
    | Optional installer defaults for the first Capell admin account. Host
    | apps can override these values directly in config, including mapping
    | them from another settings source such as Comfy.
    |
    */
    'admin_user' => [
        'name' => env('CAPELL_SETUP_ADMIN_NAME', ''),
        'email' => env('CAPELL_SETUP_ADMIN_EMAIL', ''),
        'password' => env('CAPELL_SETUP_ADMIN_PASSWORD', ''),
    ],
];
