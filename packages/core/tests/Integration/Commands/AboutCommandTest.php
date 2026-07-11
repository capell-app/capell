<?php

declare(strict_types=1);

it('shows capell-app as installed in the about command', function (): void {
    // Boot the Laravel application
    artisanCommand('about')
        ->expectsOutputToContain('Capell')
        ->doesntExpectOutputToContain('Blog')
        ->assertExitCode(0);
});
