<?php

declare(strict_types=1);

use Capell\Admin\Support\Notifications\BulkOutcomeTitle;

it('leaves the outcome unchanged when nothing was skipped', function (): void {
    expect(BulkOutcomeTitle::make(trans_choice('capell-admin::bulk_actions.publish_pages_done', 1, ['count' => 1]), 0))
        ->toBe('Published 1 page.')
        ->and(BulkOutcomeTitle::make(trans_choice('capell-admin::bulk_actions.publish_pages_done', 3, ['count' => 3]), 0))
        ->toBe('Published 3 pages.');
});

it('appends how many pages were skipped only when some were', function (): void {
    expect(BulkOutcomeTitle::make(trans_choice('capell-admin::bulk_actions.move_pages_done', 2, ['count' => 2]), 1))->toBe('Moved 2 pages. 1 page was skipped.')
        ->and(BulkOutcomeTitle::make(trans_choice('capell-admin::bulk_actions.move_pages_done', 1, ['count' => 1]), 4))->toBe('Moved 1 page. 4 pages were skipped.');
});
