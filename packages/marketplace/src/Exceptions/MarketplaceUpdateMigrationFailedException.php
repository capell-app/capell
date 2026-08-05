<?php

declare(strict_types=1);

namespace Capell\Marketplace\Exceptions;

use RuntimeException;

/**
 * A migration step of an update did not complete.
 *
 * Its own type so the failure lands on the migration stage rather than being
 * guessed at from the message — and, more importantly, so the rollback path can
 * tell it apart from a failure that happened before anything touched the
 * database.
 */
final class MarketplaceUpdateMigrationFailedException extends RuntimeException {}
