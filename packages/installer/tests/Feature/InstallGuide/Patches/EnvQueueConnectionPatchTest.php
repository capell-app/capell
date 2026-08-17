<?php

declare(strict_types=1);

use Capell\Core\Support\Patching\PatchStatus;
use Capell\Installer\Support\InstallGuide\Patches\EnvQueueConnectionPatch;
use Illuminate\Support\Facades\File;

function writeEnvQueueConnectionFixture(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'env_queue_connection_');
    File::put($path, $contents);

    return $path;
}

it('probe returns unsupported when the env file does not exist', function (): void {
    $patch = new EnvQueueConnectionPatch('/does/not/exist/.env');

    expect($patch->probe())->toBe(PatchStatus::Unsupported);
});

it('probe returns applicable when the key is missing', function (): void {
    $path = writeEnvQueueConnectionFixture("APP_NAME=TestApp\n");

    try {
        expect(new EnvQueueConnectionPatch($path)->probe())->toBe(PatchStatus::Applicable);
    } finally {
        File::delete($path);
    }
});

it('probe returns applicable when the key is sync', function (): void {
    $path = writeEnvQueueConnectionFixture("QUEUE_CONNECTION=sync\n");

    try {
        expect(new EnvQueueConnectionPatch($path)->probe())->toBe(PatchStatus::Applicable);
    } finally {
        File::delete($path);
    }
});

it('probe returns already applied when the key is set to a non sync driver', function (): void {
    $path = writeEnvQueueConnectionFixture("QUEUE_CONNECTION=database\n");

    try {
        expect(new EnvQueueConnectionPatch($path)->probe())->toBe(PatchStatus::AlreadyApplied);
    } finally {
        File::delete($path);
    }
});

it('apply sets the key to database while preserving other env variables', function (): void {
    $path = writeEnvQueueConnectionFixture("APP_NAME=TestApp\nAPP_DEBUG=false\n");

    try {
        new EnvQueueConnectionPatch($path)->apply();

        $contents = File::get($path);

        expect($contents)->toContain('APP_NAME=TestApp')
            ->and($contents)->toContain('APP_DEBUG=false')
            ->and($contents)->toContain('QUEUE_CONNECTION=database')
            ->and(substr_count($contents, 'QUEUE_CONNECTION'))->toBe(1);
    } finally {
        File::delete($path);
    }
});

it('apply throws when the patch is not applicable', function (): void {
    $path = writeEnvQueueConnectionFixture("QUEUE_CONNECTION=redis\n");

    try {
        expect(fn () => new EnvQueueConnectionPatch($path)->apply())->toThrow(RuntimeException::class);
    } finally {
        File::delete($path);
    }
});

it('patch metadata is correct', function (): void {
    $patch = new EnvQueueConnectionPatch;

    expect($patch->id())->toBe('env-queue-connection-patch');
    expect($patch->group())->toBe('environment');
    expect($patch->defaultEnabled())->toBeTrue();
    expect($patch->docUrl())->toBeNull();
    expect($patch->reason())->toBeNull();
});
