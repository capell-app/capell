<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\Scopes\LanguagesOrderScope;

it('language order scope binds language ids before building raw ordering', function (): void {
    $query = Page::query();
    $maliciousLanguageId = '1) DESC; DROP TABLE pages; --';

    LanguagesOrderScope::applyTo($query, [$maliciousLanguageId, '2', 3]);
    $bindings = $query->getQuery()->getRawBindings();

    expect($query->toSql())
        ->not->toContain($maliciousLanguageId)
        ->and(is_array($bindings['order'] ?? null) ? $bindings['order'] : [])->toBe([2, 3]);
});
