<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use RuntimeException;

final class WorkspaceException extends RuntimeException
{
    public function __construct(string $message, private readonly string $safeMessage, public readonly WorkspaceFailure $failure = WorkspaceFailure::Untrusted)
    {
        parent::__construct($message);
    }

    public function publicMessage(): string
    {
        return $this->safeMessage;
    }
}
