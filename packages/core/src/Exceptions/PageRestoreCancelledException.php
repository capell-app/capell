<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use RuntimeException;

/** Roll back the complete cascade when a model restoring listener refuses a member. */
final class PageRestoreCancelledException extends RuntimeException
{
    public static function membersNotRestorable(): self
    {
        return new self('The page restore was cancelled because a page in the restore set can no longer be restored.');
    }

    public static function membersChanged(): self
    {
        return new self('The page restore was cancelled because the pages to restore changed while it was running. Review the deleted pages and try again.');
    }
}
