<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How far an extension is allowed to move without an operator pressing a button.
 *
 * Lives in core because the column it casts lives on CapellExtension, and core
 * must never depend on the marketplace package. The marketplace reads it; core
 * only stores it.
 *
 * Deliberately not a boolean. "Automatic updates: on" is a promise nobody can
 * keep across a major version, and the difference between "take patches" and
 * "take anything" is the difference an operator actually wants to express.
 */
enum ExtensionAutoUpdatePolicyEnum: string implements HasLabel
{
    /** The default, and the only setting that never changes code unasked. */
    case None = 'none';

    case Patch = 'patch';

    case Minor = 'minor';

    /**
     * Only releases the marketplace has flagged as security fixes — including
     * major ones, because a site left on a vulnerable version is the worse
     * outcome of the two.
     */
    case Security = 'security';

    public function getLabel(): string
    {
        return (string) __('capell-core::extensions.auto_update_policies.' . $this->value);
    }

    /**
     * Whether a release of this shape may be taken automatically.
     *
     * `$securityRelease` is what separates Security from the version-shaped
     * policies: it is not a size of change, it is a reason to accept one.
     */
    public function allows(ExtensionReleaseKindEnum $releaseKind, bool $securityRelease): bool
    {
        return match ($this) {
            self::None => false,
            self::Security => $securityRelease,
            self::Patch => $releaseKind === ExtensionReleaseKindEnum::Patch,
            self::Minor => $releaseKind === ExtensionReleaseKindEnum::Patch
                || $releaseKind === ExtensionReleaseKindEnum::Minor,
        };
    }
}
