<?php

declare(strict_types=1);

namespace Capell\Marketplace\Exceptions;

use RuntimeException;

/**
 * Thrown when the post-operation health check refused to confirm the site.
 *
 * A distinct type rather than a message the caller has to pattern-match: the
 * failure stage recorded against the attempt has to be `health_check` exactly
 * when this is what happened, and inferring that from the wording of a
 * subprocess's error is how stages end up lying.
 */
final class MarketplaceHealthCheckFailedException extends RuntimeException {}
