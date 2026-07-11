<?php

declare(strict_types=1);

use Capell\Core\Actions\IncrementNameAction;

it('increments a name suffix correctly', function (): void {
    expect(IncrementNameAction::run('Page'))->toBe('Page (2)');
    expect(IncrementNameAction::run('Page (2)'))->toBe('Page (3)');
});
