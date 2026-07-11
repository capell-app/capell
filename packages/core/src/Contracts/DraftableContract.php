<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

interface DraftableContract
{
    /**
     * Returns a string key uniquely identifying this model for drafting purposes.
     * Without a workspace package installed, this method is never called.
     * With one installed, the workspace package uses this to register draftables.
     */
    public function getDraftKey(): string;
}
