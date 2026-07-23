<?php

declare(strict_types=1);

use Capell\Core\Contracts\RedirectResolver as CoreRedirectResolver;
use Capell\Frontend\Contracts\RedirectResolver as DeprecatedRedirectResolver;

it('keeps the deprecated redirect resolver alias compatible with the core contract', function (): void {
    expect(is_a(DeprecatedRedirectResolver::class, CoreRedirectResolver::class, true))->toBeTrue();
});
