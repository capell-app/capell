<?php

declare(strict_types=1);

it('runs the view cache command without errors', function (): void {
    $result = artisanCommand('view:cache');
    $result->assertExitCode(0);

    $result = artisanCommand('view:clear');
    $result->assertExitCode(0);
});
