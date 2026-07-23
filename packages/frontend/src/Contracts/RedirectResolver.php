<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Core\Contracts\RedirectResolver as CoreRedirectResolver;

/**
 * @deprecated Use {@see CoreRedirectResolver}. This compatibility alias will be removed in the next major release.
 */
interface RedirectResolver extends CoreRedirectResolver {}
