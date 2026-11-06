<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use RuntimeException;
use Throwable;

/**
 * One or more public-read withdrawal steps failed. Every other step still
 * ran, and each failed step stays owed until the retry command completes it.
 *
 * @experimental
 */
final class PublicReadWithdrawalFailedException extends RuntimeException
{
    /** @param array<string, Throwable> $failures keyed by step */
    public function __construct(public readonly array $failures)
    {
        parent::__construct(
            'Public-read withdrawal steps failed and remain owed: ' . implode(', ', array_keys($failures)) . '.',
            previous: array_values($failures)[0] ?? null,
        );
    }
}
