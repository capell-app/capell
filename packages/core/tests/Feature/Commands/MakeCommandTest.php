<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('exists')->zeroOrMoreTimes()->andReturn(false);
    $filesystem->shouldReceive('ensureDirectoryExists')->zeroOrMoreTimes()->andReturnNull();
    $filesystem->shouldReceive('put')->zeroOrMoreTimes()->andReturnTrue();

    app()->instance(Filesystem::class, $filesystem);
});

it('lists registered makers in dry-run mode', function (): void {
    artisanCommand('capell:make', [
        'maker' => 'core.action',
        '--name' => 'PublishPage',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('core.action')
        ->expectsOutputToContain('PublishPageAction.php')
        ->expectsOutputToContain('php artisan capell:make-action PublishPage')
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
