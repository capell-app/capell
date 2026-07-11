<?php

declare(strict_types=1);

it('runs clear configurators cache command successfully', function (): void {
    artisanCommand('capell:admin-clear-configurators-cache')
        ->assertExitCode(0);
});
