<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Editorial intent: the page was archived. A page cannot be published directly
 * from archived; it must be returned to draft first.
 */
final class PageArchived extends ShouldBeStored {}
