<?php

declare(strict_types=1);

use Capell\Admin\Actions\ReplicateLayoutAction as AdminReplicateLayoutAction;
use Capell\Core\Models\Layout;

it('replicates a layout', function (): void {
    $layout = Layout::factory()->createOne(['name' => 'OrigLayout']);

    $clone = AdminReplicateLayoutAction::run($layout);

    expect($clone)->toBeInstanceOf(Layout::class)
        ->and($clone->id)->not()->toBe($layout->id)
        ->and($clone->name)->toContain('OrigLayout');
});
