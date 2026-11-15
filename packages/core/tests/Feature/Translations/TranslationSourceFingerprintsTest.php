<?php

declare(strict_types=1);

use Capell\Core\Actions\Translations\ResolveChangedSourceFieldsAction;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Translation;

/** @return array{Translation, Translation} */
function fingerprintedTranslations(): array
{
    $english = Language::factory()->default()->create();
    $french = Language::factory()->create(['default' => false]);
    $page = Page::factory()->create();

    $source = Translation::factory()->translatable($page)->language($english)->create([
        'title' => 'About us',
        'content' => ['intro' => 'Welcome', 'body' => 'Our story'],
        'meta' => ['description' => 'Who we are'],
    ]);

    $french = Translation::factory()->translatable($page)->language($french)->create([
        'title' => 'À propos',
        'content' => ['intro' => 'Bienvenue', 'body' => 'Notre histoire'],
        'meta' => ['description' => 'Qui sommes-nous'],
    ]);

    return [$source, $french];
}

it('flags only the default-language field that changed since the translation was saved', function (): void {
    [$source, $french] = fingerprintedTranslations();

    expect(ResolveChangedSourceFieldsAction::run($french->refresh(), $source->refresh()))->toBe([]);

    $source->update(['content' => ['intro' => 'Hello and welcome', 'body' => 'Our story']]);

    expect(ResolveChangedSourceFieldsAction::run($french->refresh(), $source->refresh()))->toBe(['content.intro']);
});

it('clears the flags once the translation is saved again', function (): void {
    [$source, $french] = fingerprintedTranslations();
    $source->update(['title' => 'About our team', 'meta' => ['description' => 'Our team']]);

    expect(ResolveChangedSourceFieldsAction::run($french->refresh(), $source->refresh()))->toBe(['meta.description', 'meta.slug', 'title']);

    $french->update(['title' => 'Notre équipe']);

    expect(ResolveChangedSourceFieldsAction::run($french->refresh(), $source->refresh()))->toBe([]);
});

it('reports fields added to or removed from the default language', function (): void {
    [$source, $french] = fingerprintedTranslations();
    $source->update(['content' => ['intro' => 'Welcome', 'cta' => 'Contact us']]);

    expect(ResolveChangedSourceFieldsAction::run($french->refresh(), $source->refresh()))->toBe(['content.body', 'content.cta']);
});

it('does not record fingerprints on the default-language translation', function (): void {
    [$source] = fingerprintedTranslations();

    expect($source->refresh()->getAttribute('source_fingerprints'))->toBeNull();
});

it('returns null for a translation saved before tracking began', function (): void {
    [$source, $french] = fingerprintedTranslations();
    Translation::query()->whereKey($french->getKey())->update(['source_fingerprints' => null]);

    expect(ResolveChangedSourceFieldsAction::run($french->refresh(), $source->refresh()))->toBeNull();
});
