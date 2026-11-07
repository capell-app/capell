<?php

declare(strict_types=1);

use Capell\Core\Contracts\Extensions\RecordsExtensionContributionReceipt;
use Capell\Core\Contracts\LinkableContent;
use Capell\Core\Data\LinkableContentData;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptContext;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Core\Support\Links\LinkableContentRegistry;
use Capell\Core\Tests\Support\Fixtures\Autoload\TestRegistryLinkableContent;
use Illuminate\Support\Collection;

function fakeLinkableContentProvider(string $key, array $options): LinkableContent
{
    return new readonly class($key, $options) implements LinkableContent
    {
        /**
         * @param  array<int, LinkableContentData>  $options
         */
        public function __construct(
            private string $key,
            private array $options,
        ) {}

        public function key(): string
        {
            return $this->key;
        }

        public function options(?int $siteId = null, ?int $languageId = null): Collection
        {
            return collect($this->options);
        }
    };
}

it('returns registered provider options', function (): void {
    $registry = new LinkableContentRegistry;

    $option = new LinkableContentData(
        type: 'page_url',
        id: 1,
        label: 'About',
        url: '/about',
        status: true,
        site_id: 1,
        language_id: 1,
    );

    $registry->register(fakeLinkableContentProvider('pages', [$option]));

    expect($registry->provider('pages'))->toBeInstanceOf(LinkableContent::class)
        ->and($registry->all())->toHaveKey('pages')
        ->and($registry->options())->toHaveCount(1)
        ->and($registry->options()->first())->toBe($option);
});

it('replaces duplicate provider keys without duplicating options', function (): void {
    $registry = new LinkableContentRegistry;

    $firstOption = new LinkableContentData('page_url', 1, 'First', '/first', true, 1, 1);
    $replacementOption = new LinkableContentData('page_url', 2, 'Replacement', '/replacement', true, 1, 1);

    $registry->register(fakeLinkableContentProvider('pages', [$firstOption]));
    $registry->register(fakeLinkableContentProvider('pages', [$replacementOption]));

    expect($registry->all())->toHaveCount(1)
        ->and($registry->options())->toHaveCount(1)
        ->and($registry->options()->first())->toBe($replacementOption);
});

function linkableReceiptRegistry(): ExtensionContributionReceiptRegistry
{
    $receipts = new ExtensionContributionReceiptRegistry;
    app()->instance(ExtensionContributionReceiptRegistry::class, $receipts);
    app()->instance(RecordsExtensionContributionReceipt::class, $receipts);

    return $receipts;
}

it('keeps linkable content receipts owned by the declaring package when another package activation resolves the registry', function (): void {
    $receipts = linkableReceiptRegistry();
    $receipts->rememberNamespaceContext(
        'Capell\\Core\\Tests\\Support\\Fixtures\\Autoload',
        ExtensionContributionReceiptContext::forPackage('vendor/declaring', 'runtime', 'Vendor\\Declaring\\Provider'),
    );

    $receipts->withContext(
        ExtensionContributionReceiptContext::forPackage('vendor/resolving', 'runtime', 'Vendor\\Resolving\\Provider'),
        function (): void {
            (new LinkableContentRegistry)->register(new TestRegistryLinkableContent);
        },
    );

    expect($receipts->forPackage('vendor/resolving'))->toBe([])
        ->and($receipts->forPackage('vendor/declaring'))->toHaveCount(1)
        ->and($receipts->forPackage('vendor/declaring')[0]->implementation)->toBe(TestRegistryLinkableContent::class);
});

it('keeps Core-namespaced linkable content owned by Core when another package activation resolves the registry', function (): void {
    $receipts = linkableReceiptRegistry();
    $receipts->rememberNamespaceContext(
        'Capell\\Core\\',
        ExtensionContributionReceiptContext::forPackage('capell-app/core', 'runtime', 'Capell\\Core\\CoreServiceProvider'),
    );

    $receipts->withContext(
        ExtensionContributionReceiptContext::forPackage('vendor/resolving', 'runtime', 'Vendor\\Resolving\\Provider'),
        function (): void {
            (new LinkableContentRegistry)->register(new TestRegistryLinkableContent);
        },
    );

    expect($receipts->forPackage('vendor/resolving'))->toBe([])
        ->and($receipts->forPackage('capell-app/core'))->toHaveCount(1);
});

it('attributes anonymous linkable content receipts to the active package, not the interface namespace', function (): void {
    $receipts = linkableReceiptRegistry();
    $receipts->rememberNamespaceContext(
        'Capell\\Core\\',
        ExtensionContributionReceiptContext::forPackage('capell-app/core', 'runtime', 'Capell\\Core\\CoreServiceProvider'),
    );

    $receipts->withContext(
        ExtensionContributionReceiptContext::forPackage('vendor/active', 'runtime', 'Vendor\\Active\\Provider'),
        function (): void {
            (new LinkableContentRegistry)->register(fakeLinkableContentProvider('anonymous.linkable', []));
        },
    );

    expect($receipts->forPackage('capell-app/core'))->toBe([])
        ->and($receipts->forPackage('vendor/active'))->toHaveCount(1);
});
