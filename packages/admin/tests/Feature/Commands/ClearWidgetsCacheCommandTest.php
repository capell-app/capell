<?php

declare(strict_types=1);

it('runs clear widgets cache command successfully', function (): void {
    artisanCommand('capell:admin-clear-widgets-cache')
        ->assertExitCode(0);
});
