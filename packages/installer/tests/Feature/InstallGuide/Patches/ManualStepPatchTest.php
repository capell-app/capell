<?php

declare(strict_types=1);

use Capell\Core\Support\Patching\PatchStatus;
use Capell\Installer\Support\InstallGuide\Patches\ManualStepPatch;

it('configures the web server manual step', function (): void {
    $patch = ManualStepPatch::webServerConfig();

    expect($patch->id())->toBe('doc_only_web_server')
        ->and($patch->group())->toBe('Manual steps')
        ->and($patch->label())->toBe('Web server configuration')
        ->and($patch->description())->toBe('Configure your web server to serve cached static HTML directly')
        ->and($patch->reason())->toBe('This step requires manual web server configuration (Apache/Nginx)')
        ->and($patch->docUrl())->toBe('https://docs.capell.app/packages/frontend/server-config/')
        ->and($patch->defaultEnabled())->toBeFalse()
        ->and($patch->probe())->toBe(PatchStatus::Unsupported);

    expect(fn () => $patch->apply())->toThrow(
        RuntimeException::class,
        'DocOnlyWebServerPatch cannot be applied. This step requires manual web server configuration (Apache/Nginx).',
    );
});

it('configures the queue worker manual step', function (): void {
    $patch = ManualStepPatch::queueWorker();

    expect($patch->id())->toBe('doc_only_queue_worker')
        ->and($patch->group())->toBe('Manual steps')
        ->and($patch->label())->toBe('Setup Queue Worker')
        ->and($patch->description())->toBe('Setup a queue worker to process queued jobs')
        ->and($patch->reason())->toBe('This step requires manual installer using Supervisor or a process manager.')
        ->and($patch->docUrl())->toBe('https://docs.capell.app/operations/troubleshooting/#queue-worker')
        ->and($patch->defaultEnabled())->toBeFalse()
        ->and($patch->probe())->toBe(PatchStatus::Unsupported);

    expect(fn () => $patch->apply())->toThrow(
        RuntimeException::class,
        'DocOnlyQueueWorkerPatch cannot be applied. This step requires manual installer using Supervisor or a process manager.',
    );
});

it('configures the media library manual step', function (): void {
    $patch = ManualStepPatch::mediaLibrary();

    expect($patch->id())->toBe('doc_only_media_library')
        ->and($patch->group())->toBe('Manual steps')
        ->and($patch->label())->toBe('Switch to Media Library (optional)')
        ->and($patch->description())->toBe('Replace Spatie MediaLibrary with Awcodes Curator for file uploads')
        ->and($patch->reason())->toBe('Install capell-app/media-library package and run migration command')
        ->and($patch->docUrl())->toBe('https://docs.capell.app/packages/#media-backends')
        ->and($patch->defaultEnabled())->toBeFalse()
        ->and($patch->probe())->toBe(PatchStatus::Unsupported);

    expect(fn () => $patch->apply())->toThrow(
        RuntimeException::class,
        'DocOnlyMediaLibraryPatch cannot be applied. Install capell-app/media-library package and run the migration command.',
    );
});

it('gives each factory method a distinct instance with no shared mutable state', function (): void {
    expect(ManualStepPatch::webServerConfig())->not->toBe(ManualStepPatch::webServerConfig());
});
