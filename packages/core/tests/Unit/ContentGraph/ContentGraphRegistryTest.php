<?php

declare(strict_types=1);

use Capell\Core\Contracts\ContentGraph\ContentGraphExtractor;
use Capell\Core\Models\Page;
use Capell\Core\Support\ContentGraph\ContentGraphRegistry;
use Capell\Core\Support\ContentGraph\Extractors\PageContentGraphExtractor;
use Capell\Core\Tests\Support\Fixtures\Autoload\OperationTaggedPageContentGraphExtractor;
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

it('resolves tagged extractors from the current operation while preserving explicit registrations', function (): void {
    $operation = (object) ['name' => 'operation-a'];

    app()->scoped(
        OperationTaggedPageContentGraphExtractor::class,
        fn (): OperationTaggedPageContentGraphExtractor => new OperationTaggedPageContentGraphExtractor($operation->name),
    );
    app()->tag([OperationTaggedPageContentGraphExtractor::class], ContentGraphRegistry::TAG);

    $registry = resolve(ContentGraphRegistry::class);
    $registry->register(TestPageContentGraphExtractor::class);
    $registry->register(TestPageContentGraphExtractor::class);

    $operationAExtractors = $registry->forModel(Page::class);
    $operationAExtractor = collect($operationAExtractors)
        ->first(fn (ContentGraphExtractor $extractor): bool => $extractor instanceof OperationTaggedPageContentGraphExtractor);

    $operation->name = 'operation-b';
    app()->forgetScopedInstances();

    $operationBExtractors = resolve(ContentGraphRegistry::class)->forModel(Page::class);
    $operationBExtractor = collect($operationBExtractors)
        ->first(fn (ContentGraphExtractor $extractor): bool => $extractor instanceof OperationTaggedPageContentGraphExtractor);

    assert($operationAExtractor instanceof OperationTaggedPageContentGraphExtractor);
    assert($operationBExtractor instanceof OperationTaggedPageContentGraphExtractor);

    expect(resolve(ContentGraphRegistry::class))->toBe($registry)
        ->and($operationAExtractors[0])->toBeInstanceOf(TestPageContentGraphExtractor::class)
        ->and($operationBExtractors[0])->toBeInstanceOf(TestPageContentGraphExtractor::class)
        ->and(collect($operationAExtractors)->whereInstanceOf(TestPageContentGraphExtractor::class))->toHaveCount(1)
        ->and(collect($operationBExtractors)->whereInstanceOf(TestPageContentGraphExtractor::class))->toHaveCount(1)
        ->and(collect($operationAExtractors)->contains(fn (ContentGraphExtractor $extractor): bool => $extractor instanceof PageContentGraphExtractor))->toBeTrue()
        ->and(collect($operationBExtractors)->contains(fn (ContentGraphExtractor $extractor): bool => $extractor instanceof PageContentGraphExtractor))->toBeTrue()
        ->and($operationAExtractor->operation)->toBe('operation-a')
        ->and($operationBExtractor)->not->toBe($operationAExtractor)
        ->and($operationBExtractor->operation)->toBe('operation-b')
        ->and(collect($operationBExtractors)->whereInstanceOf(OperationTaggedPageContentGraphExtractor::class))->toHaveCount(1);
});
