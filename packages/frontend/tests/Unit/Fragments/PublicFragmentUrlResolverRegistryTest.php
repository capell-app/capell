<?php

declare(strict_types=1);

use Capell\Frontend\Contracts\Fragments\PublicFragmentUrlResolver;
use Capell\Frontend\Data\Fragments\PublicFragmentReferenceData;
use Capell\Frontend\Exceptions\DuplicatePublicFragmentOwner;
use Capell\Frontend\Exceptions\PublicFragmentReferenceInvalid;
use Capell\Frontend\Support\Fragments\PublicFragmentUrlResolverRegistry;

function fragmentUrlResolver(string $owner, string $url): PublicFragmentUrlResolver
{
    return new readonly class($owner, $url) implements PublicFragmentUrlResolver
    {
        public function __construct(
            private string $registeredOwner,
            private string $resolvedUrl,
        ) {}

        public function owner(): string
        {
            return $this->registeredOwner;
        }

        public function url(PublicFragmentReferenceData $reference): string
        {
            return $this->resolvedUrl . '/' . $reference->contentVersion;
        }
    };
}

function fragmentReferenceForOwner(string $owner): PublicFragmentReferenceData
{
    return new PublicFragmentReferenceData(
        owner: $owner,
        formatVersion: 1,
        pageableType: 'page',
        pageableId: 41,
        siteId: 7,
        languageId: 3,
        contentVersion: 'version-1',
        ownerContext: ['fragmentKey' => 'hero'],
    );
}

it('resolves a URL through only the explicitly registered owner', function (): void {
    $registry = new PublicFragmentUrlResolverRegistry([
        fragmentUrlResolver('layout-builder', '/_fragments/layout'),
        fragmentUrlResolver('marketing', '/_capell/fragments/marketing'),
    ]);

    expect($registry->owners())->toBe(['layout-builder', 'marketing'])
        ->and($registry->hasResolvers())->toBeTrue()
        ->and($registry->has('layout-builder'))->toBeTrue()
        ->and($registry->url(fragmentReferenceForOwner('marketing')))
        ->toBe('/_capell/fragments/marketing/version-1');
});

it('reports an empty owner set without registered resolvers', function (): void {
    $registry = new PublicFragmentUrlResolverRegistry([]);

    expect($registry->owners())->toBe([])
        ->and($registry->hasResolvers())->toBeFalse()
        ->and($registry->has('layout-builder'))->toBeFalse();
});

it('rejects duplicate owner registration during construction', function (): void {
    expect(fn (): PublicFragmentUrlResolverRegistry => new PublicFragmentUrlResolverRegistry([
        fragmentUrlResolver('layout-builder', '/first'),
        fragmentUrlResolver('layout-builder', '/second'),
    ]))->toThrow(DuplicatePublicFragmentOwner::class, 'Public fragment owner [layout-builder] is already registered.');
});

it('returns the generic invalid reference outcome for unknown owners', function (): void {
    $registry = new PublicFragmentUrlResolverRegistry([
        fragmentUrlResolver('layout-builder', '/_fragments/layout'),
    ]);

    expect(fn (): string => $registry->url(fragmentReferenceForOwner('marketing')))
        ->toThrow(PublicFragmentReferenceInvalid::class, 'Public fragment reference is invalid.');
});

it('rejects invalid registered owner names', function (string $owner): void {
    expect(fn (): PublicFragmentUrlResolverRegistry => new PublicFragmentUrlResolverRegistry([
        fragmentUrlResolver($owner, '/fragment'),
    ]))->toThrow(InvalidArgumentException::class, 'Public fragment resolver owners must use lowercase stable identifiers.');
})->with(['', 'Layout Builder', 'layout/builder']);
