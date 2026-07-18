<?php

declare(strict_types=1);

use Capell\Frontend\Support\Cache\TranslationCacheDependencyRegistry;

it('supports direct construction with resolver iterables', function (): void {
    $registry = new TranslationCacheDependencyRegistry([]);

    expect($registry)->toBeInstanceOf(TranslationCacheDependencyRegistry::class);
});
