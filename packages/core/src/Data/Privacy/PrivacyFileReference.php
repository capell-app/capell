<?php

declare(strict_types=1);

namespace Capell\Core\Data\Privacy;

use InvalidArgumentException;

/**
 * A stored file named as `disk:path` in an export section or erasure receipt.
 * Paths may contain spaces and any printable character; control characters
 * are refused. Report the path as stored: a receipt is kept as evidence, so a
 * package that names files after the person should report a path built from
 * the record id instead.
 */
final class PrivacyFileReference
{
    public static function assertValid(string $reference): void
    {
        throw_unless(
            preg_match('/^[A-Za-z0-9_.-]+:[^\x00-\x1F\x7F]+$/D', $reference) === 1,
            InvalidArgumentException::class,
            'A privacy file is reported as disk:path, without control characters.',
        );
    }

    /**
     * @param  list<string>  $references
     */
    public static function assertAllValid(array $references): void
    {
        foreach ($references as $reference) {
            self::assertValid($reference);
        }
    }
}
