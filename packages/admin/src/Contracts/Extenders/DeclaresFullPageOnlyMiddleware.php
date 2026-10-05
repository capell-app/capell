<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extenders;

/** Explicit opt-out from the secure default for extender middleware replay. */
interface DeclaresFullPageOnlyMiddleware
{
    /** @return list<class-string> */
    public function fullPageOnlyMiddleware(): array;
}
