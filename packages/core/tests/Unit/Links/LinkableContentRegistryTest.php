<?php

declare(strict_types=1);

use Capell\Core\Contracts\LinkableContent;
use Capell\Core\Data\LinkableContentData;
use Capell\Core\Support\Links\LinkableContentRegistry;
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
