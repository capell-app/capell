<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

final class CoreMakeCommandRecorder
{
    /** @var list<string> */
    public array $paths = [];

    /** @var array<string, string> */
    public array $contents = [];
}

function bindMakerCommandFilesystem(bool $fileExists = false): CoreMakeCommandRecorder
{
    $filesystem = Mockery::mock(Filesystem::class);
    $captured = new CoreMakeCommandRecorder;

    $filesystem->shouldReceive('exists')->zeroOrMoreTimes()->andReturn($fileExists);
    $filesystem->shouldReceive('ensureDirectoryExists')->zeroOrMoreTimes()->andReturnNull();
    $filesystem->shouldReceive('put')
        ->zeroOrMoreTimes()
        ->andReturnUsing(function (string $path, string $contents) use ($captured): bool {
            $captured->paths[] = $path;
            $captured->contents[$path] = $contents;

            return true;
        });

    app()->instance(Filesystem::class, $filesystem);

    return $captured;
}

it('routes capell make action through the registered maker with a data companion', function (): void {
    $captured = bindMakerCommandFilesystem();

    artisanCommand('capell:make-action', [
        'name' => 'PublishPage',
        '--data' => true,
    ])
        ->expectsOutputToContain(app_path('Actions/PublishPageAction.php'))
        ->expectsOutputToContain(app_path('Data/PublishPageData.php'))
        ->assertExitCode(Command::SUCCESS);

    expect($captured->paths)
        ->toContain(app_path('Actions/PublishPageAction.php'))
        ->toContain(app_path('Data/PublishPageData.php'));
});

it('routes capell make data through the registered maker', function (): void {
    $captured = bindMakerCommandFilesystem();
    $expectedPath = app_path('Data/HeroData.php');

    artisanCommand('capell:make-data', ['name' => 'Hero'])
        ->expectsOutputToContain($expectedPath)
        ->assertExitCode(Command::SUCCESS);

    expect($captured->paths)->toContain($expectedPath);
});

it('routes capell make extender through the registered maker', function (): void {
    $captured = bindMakerCommandFilesystem();
    $expectedPath = app_path('Extenders/HeroFieldsExtender.php');

    artisanCommand('capell:make-extender', ['name' => 'HeroFields'])
        ->expectsOutputToContain($expectedPath)
        ->assertExitCode(Command::SUCCESS);

    expect($captured->paths)->toContain($expectedPath);
});

it('routes capell make schema through the registered maker', function (): void {
    $captured = bindMakerCommandFilesystem();
    $expectedPath = app_path('Schemas/HeroSchema.php');

    artisanCommand('capell:make-schema', ['name' => 'Hero'])
        ->expectsOutputToContain($expectedPath)
        ->assertExitCode(Command::SUCCESS);

    expect($captured->paths)->toContain($expectedPath);
});

it('routes capell make blueprint through the registered maker', function (): void {
    $captured = bindMakerCommandFilesystem();
    $expectedPath = app_path('Blueprints/LandingPageBlueprint.php');

    artisanCommand('capell:make-blueprint', ['name' => 'LandingPage'])
        ->expectsOutputToContain($expectedPath)
        ->assertExitCode(Command::SUCCESS);

    expect($captured->paths)->toContain($expectedPath);
});

it('blocks legacy commands from overwriting without force', function (): void {
    $captured = bindMakerCommandFilesystem(fileExists: true);

    artisanCommand('capell:make-data', [
        'name' => 'Hero',
    ])
        ->expectsOutputToContain('already exists')
        ->assertExitCode(Command::FAILURE);

    expect($captured->paths)->toBe([]);
});

it('allows legacy commands to overwrite with force', function (): void {
    $captured = bindMakerCommandFilesystem(fileExists: true);

    artisanCommand('capell:make-data', [
        'name' => 'Hero',
        '--force' => true,
    ])
        ->expectsOutputToContain('overwrite: ' . app_path('Data/HeroData.php'))
        ->assertExitCode(Command::SUCCESS);

    expect($captured->paths)->toContain(app_path('Data/HeroData.php'));
});
