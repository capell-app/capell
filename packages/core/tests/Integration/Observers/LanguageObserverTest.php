<?php

declare(strict_types=1);

use Capell\Core\Enums\CacheEnum;
use Capell\Core\Models\Language;
use Capell\Core\Support\Cache\CapellCacheManager;
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

it('invalidates the persisted locale list when a language changes', function (): void {
    $first = Language::factory()->createOne(['code' => 'cache-a', 'order' => 100]);

    expect(Language::getLanguageLocales())->toContain($first->code);

    resolve(CapellCacheManager::class)->flushLocalCache();

    $second = Language::factory()->createOne(['code' => 'cache-b', 'order' => 101]);

    resolve(CapellCacheManager::class)->flushLocalCache();

    expect(Language::getLanguageLocales())->toContain($first->code, $second->code);
});
