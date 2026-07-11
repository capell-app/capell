<?php

declare(strict_types=1);

use Capell\Core\Models\Page;

it('boots the page model without recursively booting itself', function (): void {
    expect(Page::query()->toSql())->toBe('select * from "pages" where "pages"."deleted_at" is null');
});
