<?php

declare(strict_types=1);

use Capell\Admin\Support\Notifications\BulkOutcomeTitle;

it('uses the singular for one page and the plural otherwise', function (int $done, string $expected): void {
    expect(BulkOutcomeTitle::make('publish_pages_done', $done, 0))->toBe($expected);
})->with([
    'one' => [1, 'Published 1 page.'],
    'several' => [3, 'Published 3 pages.'],
    'none' => [0, 'Published 0 pages.'],
]);

it('appends how many pages were skipped only when some were', function (): void {
    expect(BulkOutcomeTitle::make('move_pages_done', 2, 1))->toBe('Moved 2 pages. 1 page was skipped.')
        ->and(BulkOutcomeTitle::make('move_pages_done', 1, 4))->toBe('Moved 1 page. 4 pages were skipped.')
        ->and(BulkOutcomeTitle::make('move_pages_done', 2, 0))->not->toContain('skipped');
});
