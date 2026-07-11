<?php

declare(strict_types=1);

use Capell\Admin\Contracts\ConfiguratorInterface;
use Illuminate\Filesystem\Filesystem;

it('all admin configurators implement the configurator contract', function (): void {
    $filesystem = resolve(Filesystem::class);
    $basePath = realpath(__DIR__ . '/../../../../src/Filament/Configurators');

    expect($basePath)->not->toBeFalse();
    assert(is_string($basePath));

    foreach ($filesystem->allFiles($basePath) as $file) {
        $relativePath = str_replace([$basePath . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR, '.php'], ['', '\\', ''], $file->getPathname());
        $class = 'Capell\\Admin\\Filament\\Configurators\\' . $relativePath;

        expect(class_implements($class))->toContain(ConfiguratorInterface::class);
    }
});
