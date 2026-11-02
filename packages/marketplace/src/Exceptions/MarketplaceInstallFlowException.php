<?php

declare(strict_types=1);

namespace Capell\Marketplace\Exceptions;

use RuntimeException;

/**
 * A Marketplace install or connection flow failure whose message Capell wrote
 * itself. Such messages never echo remote response text, so the flow session
 * may store and show them verbatim; every other failure is stored as a code.
 */
final class MarketplaceInstallFlowException extends RuntimeException {}
