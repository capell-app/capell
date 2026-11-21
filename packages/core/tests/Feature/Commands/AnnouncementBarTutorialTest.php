<?php

declare(strict_types=1);

use Capell\Core\Tests\Support\NestedTestProcess;
use Illuminate\Support\Facades\File;

it('runs the Announcement Bar package tests copied from the author guides', function (): void {
    $root = dirname(__DIR__, 5);
    $directory = sys_get_temp_dir() . '/capell-announcement-tutorial-' . bin2hex(random_bytes(8));
    File::ensureDirectoryExists($directory);

    try {
        foreach (['build-extension-end-to-end.md', 'testing-packages.md'] as $guide) {
            preg_match_all(
                '/^<!-- tutorial-file: ([\w.-]+(?:\/[\w.-]+)*) -->\R```\w+\R(.*?)^```$/ms',
                File::get($root . '/docs/packages/' . $guide),
                $files,
                PREG_SET_ORDER,
            );

            foreach ($files as [, $name, $contents]) {
                $path = $directory . '/' . $name;
                expect(explode('/', $name))->not->toContain('..')->not->toContain('.');
                expect(is_file($path))->toBeFalse('Duplicate tutorial file ' . $name);
                File::ensureDirectoryExists(dirname($path));
                File::put($path, $contents);
            }
        }

        $composer = json_decode(File::get($directory . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $namespaces = array_merge($composer['autoload']['psr-4'], $composer['autoload-dev']['psr-4']);

        // Use the Core CI dependency install offline. Prepend a separate package
        // loader and load the documented classes before Testbench can register
        // another loader for the root classmap's PHPStan stand-ins.
        File::ensureDirectoryExists($directory . '/vendor/pestphp/pest/bin');
        File::copy($root . '/vendor/pestphp/pest/bin/pest', $directory . '/vendor/pestphp/pest/bin/pest');
        $dependencies = var_export($root . '/vendor/autoload.php', true);
        $mappings = var_export($namespaces, true);
        File::put($directory . '/vendor/autoload.php', <<<PHP
            <?php

            require {$dependencies};
            \$loader = new \\Composer\\Autoload\\ClassLoader;
            foreach ({$mappings} as \$namespace => \$relativePath) {
                \$loader->addPsr4(\$namespace, dirname(__DIR__) . '/' . \$relativePath);
            }
            \$loader->register(true);

            foreach (\\Composer\\ClassMapGenerator\\ClassMapGenerator::createMap(dirname(__DIR__) . '/src') as \$file) {
                require_once \$file;
            }

            \$_ENV['APP_BASE_PATH'] = \\Capell\\Tests\\Support\\IsolatedTestbenchSkeleton::basePath();

            return \$loader;
            PHP);

        $process = NestedTestProcess::make(
            [
                PHP_BINARY,
                'vendor/pestphp/pest/bin/pest',
                '--configuration=phpunit.xml.dist',
                '--fail-on-risky',
                '--fail-on-empty-test-suite',
                'tests/Feature/AnnouncementBarFrontendTest.php',
            ],
            $directory,
        );
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getOutput() . $process->getErrorOutput());
    } finally {
        File::deleteDirectory($directory);
    }
});
