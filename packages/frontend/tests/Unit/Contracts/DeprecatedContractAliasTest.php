<?php

declare(strict_types=1);

use Capell\Core\Contracts\RedirectResolver as CoreRedirectResolver;

it('keeps the deprecated redirect resolver alias compatible with the core contract', function (): void {
    $deprecatedContract = implode('\\', ['Capell', 'Frontend', 'Contracts', 'RedirectResolver']);

    expect(class_implements($deprecatedContract))->toContain(CoreRedirectResolver::class);
});
