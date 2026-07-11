<?php

declare(strict_types=1);

use Capell\Core\Contracts\ContentGraph\ContentGraphExtractor;
use Capell\Core\Models\Page;
use Capell\Core\Support\ContentGraph\ContentGraphRegistry;
use Capell\Core\Tests\Support\Fixtures\Autoload\TaggedPageContentGraphExtractor;
use Capell\Core\Tests\Support\Fixtures\Autoload\TestPageContentGraphExtractor;

it('resolves extractors for a source model', function (): void {
    $registry = new ContentGraphRegistry;
    $registry->register(TestPageContentGraphExtractor::class);

    expect($registry->forModel(Page::class))
        ->toHaveCount(1)
        ->and($registry->forModel(Page::class)[0])->toBeInstanceOf(TestPageContentGraphExtractor::class);
});

it('resolves extractors from tagged services lazily', function (): void {
    app()->tag([TaggedPageContentGraphExtractor::class], ContentGraphRegistry::TAG);

    $extractors = resolve(ContentGraphRegistry::class)->forModel(Page::class);

    expect(collect($extractors)
        ->contains(fn (ContentGraphExtractor $extractor): bool => $extractor instanceof TaggedPageContentGraphExtractor))->toBeTrue();
});
