<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use RuntimeException;
use Throwable;

/**
 * An uploaded SVG could not be reduced to a safe document and must be rejected.
 */
final class UnsafeSvgUploadException extends RuntimeException
{
    public static function empty(): self
    {
        return new self('The SVG upload is empty.');
    }

    public static function tooLarge(): self
    {
        return new self('The SVG upload is too large to check safely.');
    }

    public static function notUtf8(): self
    {
        return new self('SVG uploads must be UTF-8 encoded.');
    }

    public static function containsDoctype(): self
    {
        return new self('SVG uploads may not contain a DOCTYPE or ENTITY declaration.');
    }

    public static function unparseable(Throwable $previous): self
    {
        return new self('The SVG upload could not be parsed safely.', 0, $previous);
    }

    public static function notSvgDocument(): self
    {
        return new self('The SVG upload must be an SVG document.');
    }
}
