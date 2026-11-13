<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use RuntimeException;

/**
 * An uploaded SVG could not be reduced to a safe document and must be rejected.
 */
final class UnsafeSvgUploadException extends RuntimeException {}
