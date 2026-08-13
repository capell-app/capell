<?php

declare(strict_types=1);

use Capell\Frontend\Actions\GenerateAllErrorPageCachesAction;

it('returns zero when there are no enabled sites to generate', function (): void {
    expect(GenerateAllErrorPageCachesAction::run())->toBe(0);
});
