<?php

declare(strict_types=1);

use Capell\Core\Models\Layout;
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
