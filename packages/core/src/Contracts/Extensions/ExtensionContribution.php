<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Extensions;

/**
 * Base contract for a class declared by an extension manifest contribution.
 *
 * Capell validates the declared class and its contribution-specific marker
 * before adding it to the package registry. Implementations are resolved only
 * by the subsystem that owns the declared contribution type.
 */
interface ExtensionContribution
{
    /**
     * Return the Capell extension API constraint supported by this class.
     *
     * This method is metadata-only and must not perform registration or other
     * side effects.
     */
    public static function compatibleCapellApiVersion(): string;
}
