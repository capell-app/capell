<?php

declare(strict_types=1);

use Capell\Core\Actions\Scaffolding\ScaffoldThemePackageAction;
use Capell\Core\Data\Scaffolding\ThemeScaffoldInputData;
use Capell\Core\ThemeStudio\Discovery\LocalAppThemeDefinitionRepository;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Command\Command;

it('discovers a generated local theme in the application theme directory', function (): void {
    $root = getcwd() . '/var/cap-2334/theme-' . bin2hex(random_bytes(8));
    $originalBase = app()->basePath();
    $files = new Filesystem;
    $files->ensureDirectoryExists($root);

    app()->setBasePath($root);

    try {
        artisanCommand('capell:make:theme', [
            'theme' => 'local-example',
            '--local' => true,
            '--path' => $root . '/packages',
        ])->assertExitCode(Command::SUCCESS);

        $manifest = $root . '/resources/views/capell/themes/capell-app/local-example/theme.json';
        expect($manifest)->toBeFile();
        $definitions = resolve(LocalAppThemeDefinitionRepository::class)->discover();
        expect($definitions)->toHaveKey('local-example')
            ->and($definitions['local-example']->name)->toBe('Local Example');
    } finally {
        app()->setBasePath($originalBase);
        $files->deleteDirectory($root);
    }
});

it('refuses to overwrite a local manifest when the action is called twice', function (): void {
    $root = getcwd() . '/var/theme-collision-' . bin2hex(random_bytes(8));
    $originalBase = app()->basePath();
    $files = new Filesystem;
    $files->ensureDirectoryExists($root);

    app()->setBasePath($root);

    try {
        $input = new ThemeScaffoldInputData('app/example-theme', 'App\\ExampleTheme', 'example-theme', 'example', 'Example', $root . '/package', local: true);
        ScaffoldThemePackageAction::run($input);
        $path = $root . '/resources/views/capell/themes/capell-app/example/theme.json';
        $original = file_get_contents($path);
        expect(fn (): mixed => ScaffoldThemePackageAction::run($input))->toThrow(RuntimeException::class, 'already exists');
        expect(file_get_contents($path))->toBe($original);
    } finally {
        app()->setBasePath($originalBase);
        $files->deleteDirectory($root);
    }
});
