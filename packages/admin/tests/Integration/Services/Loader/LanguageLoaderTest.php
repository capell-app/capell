<?php

declare(strict_types=1);

use Capell\Admin\Support\Loader\LanguageLoader;
use Capell\Core\Models\Language;
use Illuminate\Database\Eloquent\ModelNotFoundException;

it('loads a language by ID', function (): void {
    $lang = Language::factory()->createOne();

    $loader = resolve(LanguageLoader::class);
    $loaded = $loader->loadById($lang->id);

    expect($loaded)->toBeInstanceOf(Language::class)
        ->and($loaded->id)->toBe($lang->id);
});

it('throws when language is not found', function (): void {
    $loader = resolve(LanguageLoader::class);

    expect(fn () => $loader->loadById(999999))->toThrow(ModelNotFoundException::class);
});
