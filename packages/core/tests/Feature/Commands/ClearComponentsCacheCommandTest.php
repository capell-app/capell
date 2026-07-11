<?php

declare(strict_types=1);

it('runs clear components cache command successfully', function (): void {
    artisanCommand('capell:clear-components-cache')
        ->assertExitCode(0);
});
