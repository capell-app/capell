<?php

declare(strict_types=1);

use Capell\Core\Actions\GenerateThemeImageAction;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Events\ThemeColorsUpdated;
use Capell\Core\Models\Theme;
use Capell\Core\Support\CapellCoreHelper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('flushes theme-related caches on save/delete/restore', function (): void {
    $theme = Theme::factory()->createOne(['name' => 'Theme1']);

    Cache::driver('array')->forever(CacheEnum::HasFoundationTheme->value, true);
    Cache::driver('array')->forever(CacheEnum::Site->value . '-default-fallback', true);
    $modelRelKey = CacheEnum::RelationExists->value . '-' . Theme::class . '-' . $theme->id . '-assets';
    Cache::driver('array')->forever($modelRelKey, true);

    CapellCoreHelper::relationExists($theme, 'assets');

    $theme->delete();

    $registryAfter = Cache::driver('array')->get('capell-core-cache-keys', []);

    expect($registryAfter)->not()->toContain(CacheEnum::HasFoundationTheme->value);
});

it('dispatches ThemeColorsUpdated when a new theme is created with colors', function (): void {
    Event::fake([ThemeColorsUpdated::class]);

    Theme::factory()->createOne([
        'meta' => ['colors' => ['primary' => '#ff0000']],
    ]);

    Event::assertDispatched(ThemeColorsUpdated::class);
});

it('does not dispatch ThemeColorsUpdated when a new theme is created without colors', function (): void {
    Event::fake([ThemeColorsUpdated::class]);

    Theme::factory()->createOne(['meta' => ['other_key' => 'value']]);

    Event::assertNotDispatched(ThemeColorsUpdated::class);
});

it('dispatches ThemeColorsUpdated when an existing theme has its colors updated', function (): void {
    $theme = Theme::factory()->createOne(['meta' => ['colors' => ['primary' => '#ff0000']]]);

    Event::fake([ThemeColorsUpdated::class]);

    $theme->update(['meta' => ['colors' => ['primary' => '#00ff00']]]);

    Event::assertDispatched(ThemeColorsUpdated::class, fn (ThemeColorsUpdated $event): bool => $event->theme->is($theme));
});

it('does not dispatch ThemeColorsUpdated when meta changes but colors remain the same', function (): void {
    $theme = Theme::factory()->createOne(['meta' => ['colors' => ['primary' => '#ff0000'], 'font' => 'sans']]);

    Event::fake([ThemeColorsUpdated::class]);

    $theme->update(['meta' => ['colors' => ['primary' => '#ff0000'], 'font' => 'serif']]);

    Event::assertNotDispatched(ThemeColorsUpdated::class);
});

it('does not dispatch ThemeColorsUpdated when an unrelated field changes', function (): void {
    $theme = Theme::factory()->createOne(['name' => 'Before', 'meta' => ['colors' => ['primary' => '#ff0000']]]);

    Event::fake([ThemeColorsUpdated::class]);

    $theme->update(['name' => 'After']);

    Event::assertNotDispatched(ThemeColorsUpdated::class);
});

it('dispatches ThemeColorsUpdated with the correct theme on the event', function (): void {
    $theme = Theme::factory()->createOne(['meta' => []]);

    Event::fake([ThemeColorsUpdated::class]);

    $theme->update(['meta' => ['colors' => ['accent' => '#123456']]]);

    Event::assertDispatched(ThemeColorsUpdated::class, fn (ThemeColorsUpdated $event): bool => $event->theme->is($theme)
            && $event->theme->key === $theme->key);
});

it('clears existing generated theme image and queues a replacement when saved', function (): void {
    Storage::fake('public');
    Queue::fake();

    Storage::disk('public')->put('theme-previews/old.png', 'old');

    $theme = Theme::withoutEvents(fn (): Theme => Theme::factory()->createOne([
        'name' => 'Brand',
        'meta' => ['colors' => ['primary' => '#111111', 'secondary' => '#eeeeee']],
        'admin' => [
            'generated_image' => 'theme-previews/old.png',
            'generated_image_signature' => 'old-signature',
            'generated_image_status' => 'ready',
        ],
    ]));

    $theme->update(['name' => 'Brand Updated']);
    $theme->refresh();

    Storage::disk('public')->assertMissing('theme-previews/old.png');
    expect($theme->admin['generated_image'] ?? null)->toBeNull()
        ->and($theme->admin['generated_image_status'] ?? null)->toBe('pending');

    GenerateThemeImageAction::assertPushed(1);
});

it('does not queue generated theme images when a manual admin image exists', function (): void {
    Storage::fake('public');
    Queue::fake();

    Storage::disk('public')->put('theme-previews/old.png', 'old');

    $theme = Theme::factory()->createOne([
        'meta' => ['colors' => ['primary' => '#111111', 'secondary' => '#eeeeee']],
        'admin' => [
            'image' => 'manual/theme.png',
            'generated_image' => 'theme-previews/old.png',
            'generated_image_signature' => 'old-signature',
            'generated_image_status' => 'ready',
        ],
    ]);

    GenerateThemeImageAction::assertNotPushed();
    Storage::disk('public')->assertMissing('theme-previews/old.png');

    expect($theme->refresh()->admin)->not->toHaveKey('generated_image')
        ->not->toHaveKey('generated_image_signature')
        ->not->toHaveKey('generated_image_status');
});

it('generates a square block image from primary secondary and other colors', function (): void {
    Storage::fake('public');

    $theme = Theme::withoutEvents(fn (): Theme => Theme::factory()->createOne([
        'name' => 'Brand',
        'key' => 'brand',
        'meta' => [
            'colors' => [
                'primary' => '#111111',
                'secondary' => '#222222',
                'success' => '#333333',
            ],
        ],
        'admin' => [
            'generated_image_signature' => 'signature',
            'generated_image_status' => 'pending',
        ],
    ]));

    GenerateThemeImageAction::run((int) $theme->getKey(), 'signature');
    $theme->refresh();

    $path = (string) ($theme->admin['generated_image'] ?? '');
    Storage::disk('public')->assertExists($path);

    $image = imagecreatefrompng(Storage::disk('public')->path($path));

    expect($image)->toBeInstanceOf(GdImage::class)
        ->and(imagecolorat($image, 100, 100))->toBe(imagecolorat($image, 700, 100))
        ->and(pixelHex($image, 100, 100))->toBe('#111111')
        ->and(pixelHex($image, 1000, 100))->toBe('#222222')
        ->and(pixelHex($image, 1000, 900))->toBe('#333333')
        ->and($theme->admin['generated_image_status'] ?? null)->toBe('ready');

    imagedestroy($image);
});

it('generates a fallback image when a theme has no colors', function (): void {
    Storage::fake('public');

    $theme = Theme::withoutEvents(fn (): Theme => Theme::factory()->createOne([
        'name' => 'Fallback',
        'key' => 'fallback',
        'meta' => [
            'colors' => [],
        ],
        'admin' => [
            'generated_image_signature' => 'signature',
            'generated_image_status' => 'pending',
        ],
    ]));

    GenerateThemeImageAction::run((int) $theme->getKey(), 'signature');
    $theme->refresh();

    $path = (string) ($theme->admin['generated_image'] ?? '');
    Storage::disk('public')->assertExists($path);

    expect($theme->admin['generated_image_status'] ?? null)->toBe('ready');
});

it('generates a single color image from a short hex palette', function (): void {
    Storage::fake('public');

    $theme = Theme::withoutEvents(fn (): Theme => Theme::factory()->createOne([
        'name' => 'Single',
        'key' => 'single',
        'meta' => [
            'colors' => [
                'primary' => '#abc',
            ],
        ],
        'admin' => [
            'generated_image_signature' => 'signature',
            'generated_image_status' => 'pending',
        ],
    ]));

    GenerateThemeImageAction::run((int) $theme->getKey(), 'signature');
    $theme->refresh();

    $image = imagecreatefrompng(Storage::disk('public')->path((string) ($theme->admin['generated_image'] ?? '')));

    expect($image)->toBeInstanceOf(GdImage::class)
        ->and(pixelHex($image, 100, 100))->toBe('#aabbcc')
        ->and($theme->admin['generated_image_status'] ?? null)->toBe('ready');

    imagedestroy($image);
});

it('marks generated theme images as failed when the public disk cannot create the preview directory', function (): void {
    $rootFile = storage_path('framework/testing/public-disk-file-' . uniqid());
    File::ensureDirectoryExists(dirname($rootFile));
    File::put($rootFile, 'not a directory');

    $originalRoot = config('filesystems.disks.public.root');

    config(['filesystems.disks.public.root' => $rootFile]);
    Storage::forgetDisk('public');

    $theme = Theme::withoutEvents(fn (): Theme => Theme::factory()->createOne([
        'name' => 'Failure',
        'key' => 'failure',
        'meta' => [
            'colors' => [
                'primary' => '#111111',
            ],
        ],
        'admin' => [
            'generated_image_signature' => 'signature',
            'generated_image_status' => 'pending',
            'generated_image' => 'old.png',
        ],
    ]));

    try {
        GenerateThemeImageAction::run((int) $theme->getKey(), 'signature');
        $theme->refresh();

        expect($theme->admin['generated_image_status'] ?? null)->toBe('failed')
            ->and(array_key_exists('generated_image', $theme->admin ?? []))->toBeFalse()
            ->and($theme->admin['generated_image_error'] ?? null)->toContain('Unable to create');
    } finally {
        config(['filesystems.disks.public.root' => $originalRoot]);
        Storage::forgetDisk('public');
        File::delete($rootFile);
    }
});

function pixelHex(GdImage $image, int $xPosition, int $yPosition): string
{
    $colors = imagecolorsforindex($image, imagecolorat($image, $xPosition, $yPosition));

    return sprintf('#%02x%02x%02x', $colors['red'], $colors['green'], $colors['blue']);
}
