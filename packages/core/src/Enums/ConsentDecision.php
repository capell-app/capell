<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

/**
 * A consent decision: granted, refused, or not made yet. Callers send an analytics or
 * marketing signal only for Granted; Undecided is treated exactly like Denied for them.
 */
enum ConsentDecision: string
{
    case Granted = 'granted';
    case Denied = 'denied';
    case Undecided = 'undecided';

    /** The value the public window.CapellPrivacy.allows() returns: true, false or null. */
    public function allows(): ?bool
    {
        return match ($this) {
            self::Granted => true,
            self::Denied => false,
            self::Undecided => null,
        };
    }
}
