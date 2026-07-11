<?php

declare(strict_types=1);

it('runs cache widgets command successfully', function (): void {
    artisanCommand('capell:admin-cache-widgets')
        ->assertExitCode(0);
});
