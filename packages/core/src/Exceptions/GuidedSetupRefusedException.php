<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use RuntimeException;

/**
 * Guided setup was asked to write to a site it must not touch. Nothing was changed.
 * The reason is a stable key the interface turns into words; the message is for logs only.
 */
final class GuidedSetupRefusedException extends RuntimeException
{
    public const string ALREADY_SET_UP = 'already_set_up';

    public const string HAS_EXISTING_WORK = 'has_existing_work';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /** Setup finished, was skipped, a spec was imported, or the site has been live. */
    public static function alreadySetUp(int $siteId): self
    {
        return new self(self::ALREADY_SET_UP, sprintf('Guided setup is not owed to site %d.', $siteId));
    }

    /** The site already holds pages or edits that creating a preset's pages would overwrite. */
    public static function hasExistingWork(int $siteId): self
    {
        return new self(self::HAS_EXISTING_WORK, sprintf('Site %d already has pages or edits, so guided setup will not write to it.', $siteId));
    }
}
