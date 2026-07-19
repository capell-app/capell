<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('registers the null-or query constraint with stable bindings', function (): void {
    $query = DB::query()
        ->from('users')
        ->whereNullOr('email', '=', 'owner@example.com');

    expect($query->toSql())
        ->toBe('select * from "users" where ("email" is null or "email" = ?)')
        ->and($query->getBindings())->toBe(['owner@example.com']);
});
