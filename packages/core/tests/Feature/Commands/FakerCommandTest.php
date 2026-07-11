<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Tests\Feature\Commands\Fixtures\TestFakerCommand;
use Capell\Core\Tests\Feature\Commands\Fixtures\TestInstallCommand;
use Illuminate\Support\Facades\Artisan;

it('fans out to each package faker command with count and filters', function (): void {
    CapellCore::registerPackage(
        name: 'test',
        path: realpath(__DIR__ . '/../../../../../tests/fixtures/extension-package'),
    );

    Artisan::registerCommand(new TestInstallCommand);
    Artisan::registerCommand(new TestFakerCommand);

    TestFakerCommand::$lastCount = 0;
    TestFakerCommand::$lastSites = null;
    TestFakerCommand::$lastLanguages = null;

    artisanCommand('capell:faker', [
        '--count' => 7,
        '--packages' => 'test',
        '--sites' => 'Main Site',
        '--languages' => 'en,fr',
        '--force' => true,
    ])->assertExitCode(0);

    expect(TestFakerCommand::$lastCount)->toBe(7)
        ->and(TestFakerCommand::$lastSites)->toBe(['Main Site'])
        ->and(TestFakerCommand::$lastLanguages)->toBe(['en', 'fr']);
});

it('seeds fake pages via the core-faker command', function (): void {
    // Use a name without commas — the command splits --sites on comma separators
    $site = Site::factory()->withTranslations()->create(['name' => 'SeedTestSite']);

    $before = Page::query()->where('site_id', $site->id)->count();

    artisanCommand('capell:core-faker', [
        '--count' => 3,
        '--sites' => 'SeedTestSite',
        '--force' => true,
    ])->assertExitCode(0);

    $after = Page::query()->where('site_id', $site->id)->count();

    expect($after - $before)->toBe(3);
});

it('rejects a non-positive count', function (): void {
    artisanCommand('capell:core-faker', [
        '--count' => 0,
        '--force' => true,
    ])->assertExitCode(1);
});
