<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Editorial intent: the page was taken offline.
 */
final class PageUnpublished extends ShouldBeStored {}
