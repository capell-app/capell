<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Exceptions;

use RuntimeException;

/**
 * Base for all Capell event-sourcing failures.
 */
class EventSourcingException extends RuntimeException {}
