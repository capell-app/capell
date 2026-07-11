<?php

declare(strict_types=1);

use Capell\Core\Support\Migration\MigrationFilesystemInterface;
use Capell\Core\Tests\Support\Stubs\FakeMigrationFilesystem;

it('runs install command and does not publish files for capell:publish-migrations', function (): void {
    $fakeFileManager = new FakeMigrationFilesystem([
        'fileExists' => [],
        'isDir' => [],
    ]);

    app()->instance(MigrationFilesystemInterface::class, $fakeFileManager);

    artisanCommand('capell:frontend-install')
        ->assertExitCode(0);

    expect($fakeFileManager->calls)->not()->toContain(fn (array $call): bool => $call[0] === 'copy');
});
