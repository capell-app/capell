<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

it('lists registered makers in dry-run mode', function (): void {
    artisanCommand('capell:make', [
        'maker' => 'core.action',
        '--name' => 'PublishPage',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('core.action')
        ->expectsOutputToContain('PublishPageAction.php')
        ->expectsOutputToContain('php artisan capell:make:action PublishPage')
        ->assertExitCode(Command::SUCCESS);
});

it('blocks execution when php writes are disabled', function (): void {
    config()->set('capell.diagnostics.php_writes', 'disabled');

    artisanCommand('capell:make', [
        'maker' => 'core.action',
        '--name' => 'PublishPage',
    ])
        ->expectsOutputToContain('PHP writes are disabled')
        ->assertExitCode(Command::FAILURE);
});

it('lists registered makers without requiring a name', function (): void {
    artisanCommand('capell:make', ['--list' => true])
        ->expectsOutputToContain('core.action: Create a Capell action class')
        ->expectsOutputToContain('core.blueprint')
        ->assertExitCode(Command::SUCCESS);
});

it('names the registered makers and the list command when a maker key is not registered', function (): void {
    $exitCode = Artisan::call('capell:make', [
        'maker' => 'core.not-a-maker',
        '--name' => 'Example',
        '--no-interaction' => true,
    ]);
    $output = preg_replace('/\s+/', ' ', Artisan::output());

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($output)->toContain('Maker [core.not-a-maker] is not registered.')
        ->and($output)->toContain('Valid makers: ')
        ->and($output)->toContain('core.action')
        ->and($output)->toContain('php artisan capell:make --list');
});

it('names the registered makers when no maker is given and nobody can be asked', function (): void {
    $exitCode = Artisan::call('capell:make', ['--no-interaction' => true]);
    $output = preg_replace('/\s+/', ' ', Artisan::output());

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($output)->toContain('Maker is required in non-interactive mode.')
        ->and($output)->toContain('php artisan capell:make --list')
        ->and($output)->toContain('Valid values: ')
        ->and($output)->toContain('core.action');
});

it('shows an example name when a maker is given without one and nobody can be asked', function (): void {
    artisanCommand('capell:make', ['maker' => 'core.action', '--no-interaction' => true])
        ->expectsOutputToContain('Name is required in non-interactive mode. Pass --name=<name>, for example --name=HeroBanner.')
        ->assertExitCode(Command::FAILURE);
});

it('reports an invalid name in a dry run as a message instead of an exception', function (): void {
    artisanCommand('capell:make', [
        'maker' => 'core.action',
        '--name' => '../Escape',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('The name must start with a letter')
        ->assertExitCode(Command::FAILURE);
});

it('prints the maker next steps in a dry run', function (): void {
    artisanCommand('capell:make', [
        'maker' => 'core.page-blade-component',
        '--name' => 'PromoBanner',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Run php artisan capell:cache-components after creating new components.')
        ->assertExitCode(Command::SUCCESS);
});
