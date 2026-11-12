<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Security;

use Override;
use Pboivin\FilamentPeek\Support\Cache;

/**
 * Replaces Filament Peek's token, which is derived from the user and application key alone and so names every
 * later preview by that user, with a random token granted for one preview.
 */
final class ScopedPeekPreviewTokens extends Cache
{
    public function __construct(private readonly PeekPreviewGrants $grants) {}

    #[Override]
    public function createPreviewToken(): string
    {
        return $this->grants->issue();
    }
}
