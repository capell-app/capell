<?php

declare(strict_types=1);

it('exposes the repository quality shortcuts through the Docker harness', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 2) . '/capell');

    expect($script)
        ->toContain('pint [args]           Run Laravel Pint in the app container')
        ->toContain('"${app_exec[@]}" vendor/bin/pint "$@"')
        ->toContain('"${app_exec[@]}" composer analyze "$@"')
        ->toContain('"${app_exec[@]}" composer preflight "$@"');
});
