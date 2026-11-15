<?php

declare(strict_types=1);

use Capell\Tests\Support\OwnedApplicationPaths;

it('moves every install path and base-path config into the owned root and puts them back', function (): void {
    $skeleton = base_path();
    $routes = config('capell.install.welcome_routes_web_path');
    $env = config('capell.install.welcome_env_path');

    $workspace = new OwnedApplicationPaths(app());

    try {
        expect(base_path())->toBe($workspace->root)
            ->and(config('capell.install.welcome_routes_web_path'))->toBe($workspace->root . '/routes/web.php')
            ->and(config('capell.install.welcome_env_path'))->toBe($workspace->root . '/.env')
            ->and(is_dir(base_path('bootstrap/cache')))->toBeTrue()
            ->and(is_dir(database_path()))->toBeTrue()
            ->and(is_dir(base_path('config')))->toBeTrue();
    } finally {
        $workspace->restore();
    }

    expect(base_path())->toBe($skeleton)
        ->and(config('capell.install.welcome_routes_web_path'))->toBe($routes)
        ->and(config('capell.install.welcome_env_path'))->toBe($env)
        ->and(is_dir($workspace->root))->toBeFalse();
});
