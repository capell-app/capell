<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Security;

use Capell\Core\Enums\HtmlSanitizerProfile;

/**
 * Sanitises HTML for public output under a named profile. Packages type-hint
 * this contract instead of building their own allowlists; a package that needs
 * more markup extends a profile through HtmlSanitizerProfileRegistry, which
 * refuses scripts, event handlers, inline styles and unvalidated URL attributes.
 */
interface SanitizesPublicHtml
{
    public function sanitize(string $html, HtmlSanitizerProfile $profile = HtmlSanitizerProfile::Strict): string;
}
