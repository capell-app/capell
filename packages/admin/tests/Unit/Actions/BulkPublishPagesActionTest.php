<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\BulkPublishPagesAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;

it('returns zero counts for empty collections', function (): void {
    $actor = new User;
    $pages = new Collection;

    $result = BulkPublishPagesAction::run($pages, $actor);

    expect($result->changed())->toBe(0)
        ->and($result->blocked())->toBe(0)
        ->and($result->records)->toBe([]);
});
