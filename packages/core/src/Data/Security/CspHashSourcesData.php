<?php

declare(strict_types=1);

namespace Capell\Core\Data\Security;

use Spatie\LaravelData\Data;

/**
 * The hash sources every active package needs in a Content Security Policy.
 */
final class CspHashSourcesData extends Data
{
    /**
     * @param  list<string>  $script  sources for `script-src`
     * @param  list<string>  $style  sources for `style-src`
     */
    public function __construct(
        public readonly array $script = [],
        public readonly array $style = [],
    ) {}
}
