<?php

declare(strict_types=1);

use Capell\Core\Events\ServingCapell;
use Capell\Core\Facades\CapellCore;

it('fires serving listener when ServingCapell is dispatched', function (): void {
    $wasCalled = false;

    CapellCore::serving(function (ServingCapell $event) use (&$wasCalled): void {
        $wasCalled = true;
    });

    event(new ServingCapell);

    expect($wasCalled)->toBeTrue();
});
