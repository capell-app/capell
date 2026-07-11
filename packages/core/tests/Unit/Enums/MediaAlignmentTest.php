<?php

declare(strict_types=1);

use Capell\Core\Enums\MediaAlignment;
use Filament\Support\Contracts\HasLabel;

it('exposes the four placement options with labels', function (): void {
    expect(MediaAlignment::cases())->toHaveCount(4)
        ->and(MediaAlignment::Top)->toBeInstanceOf(HasLabel::class)
        ->and(MediaAlignment::Top->getLabel())->toBe('Top (full width)')
        ->and(MediaAlignment::Bottom->getLabel())->toBe('Bottom (full width)')
        ->and(MediaAlignment::Left->getLabel())->toBe('Left (one third)')
        ->and(MediaAlignment::Right->getLabel())->toBe('Right (one third)');
});
