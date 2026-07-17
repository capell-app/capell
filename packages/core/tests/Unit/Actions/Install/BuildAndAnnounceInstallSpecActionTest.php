<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\BuildAndAnnounceInstallSpecAction;
use Capell\Core\Events\CapellInstalled;
use Illuminate\Support\Facades\Event;

it('builds the resolved spec and announces the completed install', function (): void {
    $temporaryPath = tempnam(sys_get_temp_dir(), 'capell-core-install-spec-');

    if ($temporaryPath === false) {
        throw new RuntimeException('Could not create a temporary install spec.');
    }

    $specPath = $temporaryPath . '.json';
    unlink($temporaryPath);
    file_put_contents($specPath, json_encode([
        'site' => ['name' => 'Action Fixture Site'],
        'theme' => ['key' => 'default'],
        'pages' => [[
            'name' => 'Home',
            'slug' => 'home',
            'title' => 'Home',
            'pageType' => 'default',
        ]],
    ], JSON_THROW_ON_ERROR));
    $resolvedSpecPath = realpath($specPath);

    Event::fake([CapellInstalled::class]);

    try {
        (new BuildAndAnnounceInstallSpecAction)->handle($specPath, true);
    } finally {
        unlink($specPath);
    }

    Event::assertDispatched(
        CapellInstalled::class,
        fn (CapellInstalled $event): bool => $event->specPath === $resolvedSpecPath && $event->seededDefaults,
    );
});

it('does nothing when no spec option was supplied', function (): void {
    Event::fake([CapellInstalled::class]);

    (new BuildAndAnnounceInstallSpecAction)->handle(null, false);

    Event::assertNotDispatched(CapellInstalled::class);
});
