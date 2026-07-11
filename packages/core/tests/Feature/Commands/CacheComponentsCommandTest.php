<?php

declare(strict_types=1);

it('runs cache components command successfully', function (): void {
    artisanCommand('capell:cache-components')
        ->assertExitCode(0);
});
