<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use RuntimeException;
use Throwable;

/** A console command needs input that only an interactive prompt or a named option can supply. */
final class NonInteractiveInputRequiredException extends RuntimeException
{
    public function __construct(public readonly string $requirement, public readonly string $hint = '', ?Throwable $previous = null)
    {
        parent::__construct(sprintf(
            '%s is required in non-interactive mode.%s',
            $requirement,
            $hint !== '' ? ' ' . $hint : '',
        ), previous: $previous);
    }

    /** Add another missing input so one run names everything the caller has to pass. */
    public function withAlsoRequired(string $hint): self
    {
        return new self($this->requirement, trim($this->hint . ' Also: ' . $hint), $this);
    }
}
