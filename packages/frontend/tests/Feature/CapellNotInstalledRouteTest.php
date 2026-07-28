<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\get;

it('does not redirect frontend catch all requests to the installer before Capell is installed', function (): void {
    Schema::partialMock()
        ->shouldReceive('hasTable')
        ->with('sites')
        ->andReturnFalse();

    get('/missing-capell-page')->assertNotFound();
});
