<?php

declare(strict_types=1);

namespace Capell\Core\Testing\Assertions;

use AssertionError;
use Capell\Core\Support\Security\PublicBladeSourceScanner;

/**
 * Asserts that a package's public Blade views hold no authoring state, signed editor URLs, record keys, secrets,
 * query builders or cache access. Public views receive hydrated render data; see PublicBladeSourceScanner for which
 * views count as public.
 */
final class AssertsPublicViewSafety
{
    public const string CONTRACT_ID = 'public-output.views';

    public static function run(string $packageRoot): void
    {
        $violations = (new PublicBladeSourceScanner)->violations($packageRoot);

        throw_if($violations !== [], AssertionError::class, sprintf("[%s] %s:\n- %s", self::CONTRACT_ID, $packageRoot, implode("\n- ", $violations)));
    }
}
