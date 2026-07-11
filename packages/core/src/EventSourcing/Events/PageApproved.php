<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Editorial intent: the page under review was approved.
 */
final class PageApproved extends ShouldBeStored {}
