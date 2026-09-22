<?php

declare(strict_types=1);

use Capell\Core\Data\Extensions\ExtensionContributionReceiptData;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptContext;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;

it('deduplicates exact receipts while retaining every distinct receipt in insertion order', function (): void {
    $registry = new ExtensionContributionReceiptRegistry;
    $receipt = static fn (
        string $owner = 'vendor/package',
        string $bucket = 'admin',
        ExtensionContributionType $type = ExtensionContributionType::AdminResource,
        string $key = 'resource:key',
        string $implementation = 'ExampleResource',
        string $source = 'ExampleProvider',
        bool $builtIn = false,
    ): ExtensionContributionReceiptData => new ExtensionContributionReceiptData($owner, $bucket, $type, $key, $implementation, $source, $builtIn);
    $variants = [
        $receipt(),
        $receipt(owner: 'other/package'),
        $receipt(bucket: 'runtime'),
        $receipt(type: ExtensionContributionType::AdminPage),
        $receipt(key: 'resource:other'),
        $receipt(implementation: 'OtherResource'),
        $receipt(source: 'OtherProvider'),
        $receipt(builtIn: true),
    ];

    foreach ([...$variants, ...$variants] as $variant) {
        $context = new ExtensionContributionReceiptContext($variant->ownerPackage, $variant->providerBucket, $variant->sourceClass, $variant->foundationBuiltIn);
        $registry->withContext($context, function () use ($registry, $variant): void {
            $registry->recordContribution($variant->type, $variant->key, $variant->implementation, $variant->sourceClass, $variant->providerBucket);
        });
    }

    $serialise = static fn (ExtensionContributionReceiptData $receipt): array => $receipt->toArray();

    expect(array_map($serialise, $registry->all()))->toBe(array_map($serialise, $variants));
});

it('can record the same receipt again after clearing the registry', function (): void {
    $registry = new ExtensionContributionReceiptRegistry;
    $context = new ExtensionContributionReceiptContext('vendor/package', 'admin', 'ExampleProvider');
    $record = function () use ($registry, $context): void {
        $registry->withContext($context, function () use ($registry): void {
            $registry->recordContribution(ExtensionContributionType::AdminResource, 'resource:key', 'ExampleResource', 'ExampleProvider');
        });
    };

    $record();
    $expected = $registry->all()[0]->toArray();
    $registry->clear();

    expect($registry->all())->toBe([]);

    $record();

    expect($registry->all())->toHaveCount(1)
        ->and($registry->all()[0]->toArray())->toBe($expected);
});
