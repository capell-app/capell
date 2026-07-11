<?php

declare(strict_types=1);

it('runs cache configurators command successfully', function (): void {
    artisanCommand('capell:admin-cache-configurators')
        ->assertExitCode(0);
});
