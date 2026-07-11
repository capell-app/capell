<?php

declare(strict_types=1);

use Capell\Core\Models\RedirectHealthSnapshot;
use Illuminate\Database\Eloquent\MassAssignmentException;

it('keeps redirect health snapshots closed to arbitrary mass assignment', function (): void {
    $snapshot = new RedirectHealthSnapshot;

    expect(fn () => $snapshot->fill([
        'page_url_id' => 1,
        'source_url' => 'https://example.test/old',
        'target_url' => 'https://example.test/new',
        'has_chain' => false,
        'has_loop' => false,
        'warning_count' => 0,
        'error_count' => 0,
        'computed_at' => now(),
        'id' => 999,
        'is_admin' => true,
    ]))->toThrow(MassAssignmentException::class);
});
