<?php

declare(strict_types=1);

use Capell\Core\Enums\CacheEnum;
use Capell\Core\Models\Language;
use Illuminate\Support\Facades\Cache;

it('flushes language-related caches on save/delete/restore', function (): void {
    $lang = Language::factory()->createOne();

    // Prime keys
    Cache::driver('array')->forever(CacheEnum::HasDefaultLanguage->value, true);
    $codesKey = 'language-codes-by-ids-' . hash('sha256', json_encode([$lang->id]));
    Cache::driver('array')->forever($codesKey, ['en']);

    $lang->name = 'Updated';
    $lang->save();

    $registry = Cache::driver('array')->get('capell-core-cache-keys', []);
    expect($registry)->not()->toContain(CacheEnum::HasDefaultLanguage->value);

    $lang->delete();
    $registryAfter = Cache::driver('array')->get('capell-core-cache-keys', []);
    expect($registryAfter)->not()->toContain(CacheEnum::HasDefaultLanguage->value);
});
