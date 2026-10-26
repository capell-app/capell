<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\ReconcilePageSiteTranslationsAction;
use Capell\Admin\Tests\Support\ScopedAdminUser;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Database\Eloquent\ModelNotFoundException;

uses(CreatesAdminUser::class);

it('retains complete translation payloads and keys across same-site and default-language changes', function (): void {
    test()->actingAsAdmin();
    $english = Language::factory()->createOne();
    $french = Language::factory()->createOne(['default' => false]);
    $site = Site::factory()->language($english)->withTranslations([$english, $french])->createOne();
    $translations = [
        'english-key' => ['language_id' => (string) $english->id, 'title' => 'English title', 'content' => '<p>English</p>', 'meta' => ['slug' => 'hand-edited'], 'slug_auto_update_disabled' => true],
        'french-key' => ['language_id' => $french->id, 'title' => 'Titre français', 'content' => ['blocks' => ['draft']], 'meta' => ['slug' => 'francais'], 'extension_field' => ['kept' => true]],
    ];

    $unchanged = ReconcilePageSiteTranslationsAction::run($site->id, $translations, 'Internal name');
    $site->update(['language_id' => $french->id]);
    $changed = ReconcilePageSiteTranslationsAction::run($site->id, $translations, 'Internal name');

    expect($unchanged->translations)->toBe($translations)
        ->and($changed->translations)->toBe($translations)
        ->and($changed->unsupportedLanguageIds)->toBe([]);
});

it('adds missing destination languages once and removes unsupported content only on explicit resolution', function (): void {
    test()->actingAsAdmin();
    $english = Language::factory()->createOne();
    $french = Language::factory()->createOne(['default' => false]);
    $site = Site::factory()->language($english)->withTranslations($english)->createOne();
    $translations = ['draft-key' => ['language_id' => $french->id, 'title' => 'French draft', 'content' => '<p>Recoverable</p>', 'meta' => ['slug' => 'manual']]];

    $pending = ReconcilePageSiteTranslationsAction::run($site->id, $translations, 'Internal name');
    $again = ReconcilePageSiteTranslationsAction::run($site->id, $pending->translations, 'Changed name');

    expect($pending->translations['draft-key'])->toBe($translations['draft-key'])
        ->and($pending->unsupportedLanguageIds)->toBe([$french->id])
        ->and($pending->unsupportedLanguageNames)->toBe([$french->name])
        ->and($again->translations)->toBe($pending->translations);

    $resolved = ReconcilePageSiteTranslationsAction::run($site->id, $pending->translations, removeUnsupported: true);
    expect($resolved->translations)->not->toHaveKey('draft-key');
    expect(collect($resolved->translations)->pluck('language_id')->all())->toBe([$english->id]);
    expect(collect($resolved->translations)->sole()['title'])->toBe('Internal name');
});

it('does not resolve languages for an inaccessible destination site', function (): void {
    $assigned = Site::factory()->withTranslations()->createOne();
    $foreign = Site::factory()->withTranslations()->createOne();
    test()->actingAs(ScopedAdminUser::make(collect([$assigned->id])));

    expect(fn () => ReconcilePageSiteTranslationsAction::run($foreign->id, []))->toThrow(ModelNotFoundException::class);
});

it('removes only null metadata placeholders from retained translations', function (): void {
    test()->actingAsAdmin();
    $english = Language::factory()->createOne();
    $french = Language::factory()->createOne(['default' => false]);
    $site = Site::factory()->language($english)->withTranslations($english)->createOne();
    $meta = ['slug' => 'manual-slug', 'summary' => '', 'enabled' => false, 'order' => 0, 'extension' => ['value' => null]];
    $translations = [
        'english' => ['language_id' => $english->id, 'meta' => [...$meta, 'title' => null, 'description' => null]],
        'french' => ['language_id' => $french->id, 'meta' => [...$meta, 'title' => null, 'description' => null]],
    ];

    $transition = ReconcilePageSiteTranslationsAction::run($site->id, $translations);

    expect($transition->translations['english']['meta'])->toBe($meta)
        ->and($transition->translations['french']['meta'])->toBe($meta)
        ->and($transition->unsupportedLanguageIds)->toBe([$french->id]);
});
