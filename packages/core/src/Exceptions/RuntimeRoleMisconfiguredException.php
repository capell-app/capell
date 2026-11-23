<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use Capell\Core\Enums\RuntimeRole;
use RuntimeException;

/**
 * A node was told to run as a split runtime role but would not actually enforce it.
 *
 * Raised before the translator exists (during bootstrap and provider registration), so
 * the messages are plain English operator text rather than translation keys.
 */
final class RuntimeRoleMisconfiguredException extends RuntimeException
{
    public static function bootstrapMissing(RuntimeRole $role): self
    {
        return new self(sprintf(
            'CAPELL_RUNTIME_ROLE is [%1$s] but this application never called RuntimeRoleBootstrap::configure($app), so it would boot the full provider graph as if it were combined. '
            . 'Add "use Capell\Core\Support\Runtime\RuntimeRoleBootstrap;" and "RuntimeRoleBootstrap::configure($app);" to bootstrap/app.php after ->create() and before "return $app;", '
            . 'or set CAPELL_RUNTIME_ROLE=combined. Run "php artisan capell:doctor" to inspect the node.',
            $role->value,
        ));
    }
}
