<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Editorial intent: the page was submitted for review.
 */
final class PageSubmittedForReview extends ShouldBeStored {}
