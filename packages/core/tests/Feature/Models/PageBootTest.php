<?php

declare(strict_types=1);

use Capell\Core\Models\Page;

it('boots the page model without recursively booting itself', function (): void {
    $query = Page::query();
    $grammar = $query->getQuery()->getGrammar();

    expect($query->toSql())->toBe(sprintf(
        'select * from %s where %s is null',
        $grammar->wrap('pages'),
        $grammar->wrap('pages.deleted_at'),
    ));
});
