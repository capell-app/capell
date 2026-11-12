<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Guided setup published the site, but the logo held back since setup could not be attached.
 * The site stays published and the logo file is kept, so attaching it can be tried again.
 */
final class GuidedSetupLogoNotAttachedException extends RuntimeException
{
    public static function forSite(int $siteId, Throwable $previous): self
    {
        return new self(sprintf('Site %d was published, but its guided setup logo could not be attached.', $siteId), 0, $previous);
    }
}
