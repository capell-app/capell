<?php

declare(strict_types=1);

use Capell\Tests\Support\OwnedApplicationPaths;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->workspace = new OwnedApplicationPaths(app());
});

afterEach(function (): void {
    $this->workspace->restore();
});

function prepareMakerCommandFilesystem(bool $fileExists = false): void
{
    if ($fileExists) {
        $files = new Filesystem;
        $files->ensureDirectoryExists(app_path('Data'));
        $files->put(app_path('Data/HeroData.php'), 'Existing user-owned data');
    }
}

it('routes capell make action through the registered maker with a data companion', function (): void {
    prepareMakerCommandFilesystem();

    artisanCommand('capell:make-action', [
        'name' => 'PublishPage',
        '--data' => true,
    ])
        ->expectsOutputToContain(app_path('Actions/PublishPageAction.php'))
        ->expectsOutputToContain(app_path('Data/PublishPageData.php'))
        ->assertExitCode(Command::SUCCESS);

    expect(app_path('Actions/PublishPageAction.php'))->toBeFile()
        ->and(app_path('Data/PublishPageData.php'))->toBeFile();
});

it('routes capell make data through the registered maker', function (): void {
    prepareMakerCommandFilesystem();
    $expectedPath = app_path('Data/HeroData.php');

    artisanCommand('capell:make-data', ['name' => 'Hero'])
        ->expectsOutputToContain($expectedPath)
        ->assertExitCode(Command::SUCCESS);

    expect($expectedPath)->toBeFile();
});

it('routes capell make extender through the registered maker', function (): void {
    prepareMakerCommandFilesystem();
    $expectedPath = app_path('Extenders/HeroFieldsExtender.php');

    artisanCommand('capell:make-extender', ['name' => 'HeroFields'])
        ->expectsOutputToContain($expectedPath)
        ->assertExitCode(Command::SUCCESS);

    expect($expectedPath)->toBeFile();
});

it('routes capell make schema through the registered maker', function (): void {
    prepareMakerCommandFilesystem();
    $expectedPath = app_path('Schemas/HeroSchema.php');

    artisanCommand('capell:make-schema', ['name' => 'Hero'])
        ->expectsOutputToContain($expectedPath)
        ->assertExitCode(Command::SUCCESS);

    expect($expectedPath)->toBeFile();
});

it('routes capell make blueprint through the registered maker', function (): void {
    prepareMakerCommandFilesystem();
    $expectedPath = app_path('Blueprints/LandingPageBlueprint.php');

    artisanCommand('capell:make-blueprint', ['name' => 'LandingPage'])
        ->expectsOutputToContain($expectedPath)
        ->assertExitCode(Command::SUCCESS);

    expect($expectedPath)->toBeFile();
});

it('blocks legacy commands from overwriting without force', function (): void {
    prepareMakerCommandFilesystem(fileExists: true);

    artisanCommand('capell:make-data', [
        'name' => 'Hero',
    ])
        ->expectsOutputToContain('already exists')
        ->assertExitCode(Command::FAILURE);

    expect(file_get_contents(app_path('Data/HeroData.php')))->toBe('Existing user-owned data');
});

it('allows legacy commands to overwrite with force', function (): void {
    prepareMakerCommandFilesystem(fileExists: true);

    artisanCommand('capell:make-data', [
        'name' => 'Hero',
        '--force' => true,
    ])
        ->expectsOutputToContain('overwrite: ' . app_path('Data/HeroData.php'))
        ->assertExitCode(Command::SUCCESS);

    expect(app_path('Data/HeroData.php'))->toBeFile()
        ->and(file_get_contents(app_path('Data/HeroData.php')))->not->toBe('Existing user-owned data');
});
