<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Themes\LayoutViewRegistry;
use Capell\Core\Support\Themes\ThemeChromeRegistry;
use Illuminate\Validation\ValidationException;

it('rejects unregistered view selectors from non-form model writers', function (string $model, string $field, mixed $value): void {
    $record = $model::factory()->make(['key' => 'selector-test', 'meta' => [$field => $value]]);
    expect(fn () => $record->save())->toThrow(ValidationException::class);
})->with([
    [Layout::class, 'master_file', 'admin.secret'],
    [Layout::class, 'layout_file', 'admin.secret'],
    [Theme::class, 'header_file', 'admin.secret'],
    [Theme::class, 'footer_file', 'admin.secret'],
    [Layout::class, 'master_file', ['invalid']],
    [Theme::class, 'header_file', 123],
]);

it('persists registered selectors and preserves unrelated stored metadata', function (): void {
    $layouts = resolve(LayoutViewRegistry::class);
    $layouts->registerMaster('host::page', 'Host page');
    $layouts->registerLayout('host::shell', 'Host shell');

    $chrome = resolve(ThemeChromeRegistry::class);
    $chrome->registerHeader('host::header', 'Host header');
    $chrome->registerFooter('host::footer', 'Host footer');

    $layoutMeta = ['master_file' => 'host::page', 'layout_file' => 'host::shell', 'unrelated' => ['retained' => true]];
    $themeMeta = ['header_file' => 'host::header', 'footer_file' => 'host::footer', 'header' => true];
    $layout = Layout::factory()->create(['meta' => $layoutMeta]);
    $theme = Theme::factory()->create(['meta' => $themeMeta]);
    expect($layout->fresh()->meta)->toEqual($layoutMeta)
        ->and($theme->fresh()->meta)->toEqual($themeMeta);

    expect(fn () => $layout->update(['meta' => ['master_file' => 'admin.secret']]))->toThrow(ValidationException::class);
    expect($layout->fresh()->meta)->toEqual($layoutMeta);
    expect(fn () => $theme->update(['meta' => ['footer_file' => 'admin.secret']]))->toThrow(ValidationException::class);
    expect($theme->fresh()->meta)->toEqual($themeMeta);
});

it('allows absent and empty optional selectors', function (mixed $value): void {
    $layout = Layout::factory()->create(['meta' => ['master_file' => $value, 'layout_file' => $value]]);
    $theme = Theme::factory()->create(['meta' => ['header_file' => $value, 'footer_file' => $value]]);
    expect($layout->fresh()->meta)->toEqual($layout->meta)
        ->and($theme->fresh()->meta)->toEqual($theme->meta);
})->with([null, '']);

it('rejects private logo views on site and blueprint writes', function (bool $blueprint): void {
    $record = $blueprint
        ? Blueprint::factory()->site()->make(['meta' => ['logo_blade_view' => 'private.report']])
        : Site::factory()->make(['meta' => ['logo_blade_view' => 'private.report']]);
    expect(fn () => $record->save())->toThrow(ValidationException::class);
})->with([false, true]);

it('validates effective inherited logos and permits registered and disabled logos', function (): void {
    resolve(ThemeChromeRegistry::class)->registerLogo('host::logo', 'Host logo');
    $blueprint = Blueprint::factory()->site()->create(['meta' => ['logo_blade_view' => 'host::logo']]);
    $site = Site::factory()->create(['blueprint_id' => $blueprint->id, 'meta' => []]);
    expect($site->fresh()->getMeta('logo_blade_view'))->toBe('host::logo');
    expect($site->update(['meta' => ['logo_blade_view' => false]]))->toBeTrue();

    // Simulate legacy metadata written before selector validation existed.
    $blueprint->forceFill(['meta' => ['logo_blade_view' => 'private.report']])->saveQuietly();
    $site = $site->fresh();
    expect(fn () => $site->update(['meta' => []]))->toThrow(ValidationException::class);
    expect($site->fresh()->meta)->toBe(['logo_blade_view' => false]);
});

it('rejects inherited private logos when the default blueprint is assigned during creation', function (): void {
    $blueprint = Blueprint::factory()->site()->create(['default' => true]);
    $blueprint->forceFill(['meta' => ['logo_blade_view' => 'private.report']])->saveQuietly();
    $site = Site::factory()->make(['blueprint_id' => null, 'meta' => []]);
    expect(fn () => $site->save())->toThrow(ValidationException::class);
});
