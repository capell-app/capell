<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Upgrade;

/** The public pages the HTML cache stands-in store, and which of them were regenerated and purged from the edge. */
final class StoredPublicPages
{
    /** @var array<string, string> Each stored page's state: served, stale or regenerated. */
    public array $pages = [];

    /** @var list<string> */
    public array $edgePurges = [];

    /** @var list<string> Pages whose regeneration fails. */
    public array $failing = [];

    public bool $edgeReady = true;

    /** @param  list<string>  $urls */
    public function __construct(array $urls = ['/', '/about', '/contact'])
    {
        foreach ($urls as $url) {
            $this->pages[$url] = 'served';
        }
    }
}
