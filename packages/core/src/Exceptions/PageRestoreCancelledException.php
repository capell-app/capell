<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use RuntimeException;

/** Roll back the complete cascade when a model restoring listener refuses a member. */
final class PageRestoreCancelledException extends RuntimeException {}
