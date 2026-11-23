<?php

declare(strict_types=1);

use Capell\Core\Actions\BuildSiteFromSpecFileAction;
use Capell\Core\Actions\CreateDefaultLanguagesAction;
use Capell\Core\Actions\CreateThemeAction;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use Symfony\Component\Console\Command\Command;

beforeEach(function (): void {
    // The seeded English default carries the flag "gb-eng"; the explicit spec below asks for "gb".
    CreateDefaultLanguagesAction::run(['en']);
    Queue::fake();
});

function sharedRowsSpecPath(string $siteName = 'Shared Rows Co', string $primary = '#123456'): string
{
    $spec = CapellSiteSpecData::validateAndCreate([
        'site' => ['name' => $siteName, 'businessName' => 'Shared Rows'],
        'language' => ['code' => 'en', 'name' => 'English', 'locale' => 'en_GB', 'flag' => 'gb', 'default' => true],
        'theme' => ['key' => 'default', 'colors' => ['primary' => $primary]],
        'pages' => [[
            'name' => 'Home',
            'slug' => 'home',
            'title' => 'Home title',
            'pageType' => 'default',
            'order' => 0,
            'sections' => [['type' => 'content', 'content' => '<p>Imported copy.</p>']],
        ]],
        'initialVisibility' => 'private',
        'acknowledgePublic' => false,
    ]);
    $path = tempnam(sys_get_temp_dir(), 'capell-site-spec-shared-');
    file_put_contents($path, json_encode($spec->toArray(), JSON_THROW_ON_ERROR));

    return $path;
}

it('refuses to rewrite an existing language or theme and writes nothing', function (): void {
    $theme = CreateThemeAction::run('default', 'Default');
    $themeMeta = $theme->refresh()->meta;
    $path = sharedRowsSpecPath();

    try {
        $command = $this->artisan('capell:site-spec-import', ['spec' => $path]);
        assert($command instanceof PendingCommand);
        $command->expectsOutputToContain('language [en] flag')
            ->assertExitCode(Command::FAILURE)
            ->run();
    } finally {
        unlink($path);
    }

    expect(Language::query()->where('code', 'en')->value('flag'))->toBe('gb-eng')
        ->and(Theme::query()->where('key', 'default')->firstOrFail()->meta)->toBe($themeMeta)
        ->and(Site::query()->where('name', 'Shared Rows Co')->exists())->toBeFalse();
});

it('names the theme setting that differs when only the theme would change', function (): void {
    Language::query()->where('code', 'en')->firstOrFail()->forceFill(['flag' => 'gb'])->save();
    CreateThemeAction::run('default', 'Default');
    $path = sharedRowsSpecPath();

    try {
        expect(fn (): Site => BuildSiteFromSpecFileAction::run($path))
            ->toThrow(RuntimeException::class, 'theme [default] colors.primary')
            ->and(fn (): Site => BuildSiteFromSpecFileAction::run($path))
            ->toThrow(RuntimeException::class, '--overwrite-shared');
    } finally {
        unlink($path);
    }

    expect(Site::query()->where('name', 'Shared Rows Co')->exists())->toBeFalse();
});

it('replaces the shared rows only when the caller asks to overwrite them', function (): void {
    CreateThemeAction::run('default', 'Default');
    $path = sharedRowsSpecPath();

    try {
        $command = $this->artisan('capell:site-spec-import', ['spec' => $path, '--overwrite-shared' => true]);
        assert($command instanceof PendingCommand);
        $command->assertExitCode(Command::SUCCESS)->run();
    } finally {
        unlink($path);
    }

    expect(Language::query()->where('code', 'en')->value('flag'))->toBe('gb')
        ->and(Theme::query()->where('key', 'default')->firstOrFail()->meta['colors']['primary'] ?? null)->toBe('#123456')
        ->and(Site::query()->where('name', 'Shared Rows Co')->exists())->toBeTrue();
});

it('reuses an identical existing language and theme without asking', function (): void {
    Language::query()->where('code', 'en')->firstOrFail()->forceFill(['flag' => 'gb'])->save();
    $path = sharedRowsSpecPath(primary: '#123456');
    $theme = CreateThemeAction::run('default', 'Default');
    $theme->forceFill(['meta' => [...($theme->meta ?? []), 'colors' => ['primary' => '#123456']]])->save();

    try {
        $command = $this->artisan('capell:site-spec-import', ['spec' => $path]);
        assert($command instanceof PendingCommand);
        $command->assertExitCode(Command::SUCCESS)->run();
    } finally {
        unlink($path);
    }

    expect(Site::query()->where('name', 'Shared Rows Co')->exists())->toBeTrue();
});

it('imports a spec that only names the language onto a stock install without a flag or a change', function (): void {
    // A stock install seeds English with the flag "gb-eng". The author wrote only the code, so the
    // spec's own default flag ("gb") is not an instruction and must neither block nor rewrite the row.
    $path = tempnam(sys_get_temp_dir(), 'capell-site-spec-stock-');
    file_put_contents($path, json_encode([
        'site' => ['name' => 'Stock Install Co', 'businessName' => 'Stock Install'],
        'language' => ['code' => 'en'],
        'theme' => ['key' => 'default'],
        'pages' => [[
            'name' => 'Home',
            'slug' => 'home',
            'title' => 'Home title',
            'pageType' => 'default',
            'order' => 0,
            'sections' => [['type' => 'content', 'content' => '<p>Imported copy.</p>']],
        ]],
    ], JSON_THROW_ON_ERROR));
    $before = Language::query()->where('code', 'en')->firstOrFail()->only(['name', 'locale', 'flag', 'default']);

    try {
        $command = $this->artisan('capell:site-spec-import', ['spec' => $path]);
        assert($command instanceof PendingCommand);
        $command->assertExitCode(Command::SUCCESS)->run();
    } finally {
        unlink($path);
    }

    expect(Site::query()->where('name', 'Stock Install Co')->exists())->toBeTrue()
        ->and(Language::query()->where('code', 'en')->firstOrFail()->only(['name', 'locale', 'flag', 'default']))->toBe($before)
        ->and($before['flag'])->toBe('gb-eng');
});
