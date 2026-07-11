<?php

declare(strict_types=1);

namespace Capell\Installer\Data\InstallGuide;

use Capell\Installer\Support\InstallGuide\PatchResult;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

class ApplyPatchesResultData extends Data
{
    /**
     * @param  Collection<int, PatchResult>  $results
     */
    public function __construct(
        public Collection $results,
    ) {}

    /**
     * @return Collection<int, PatchResult>
     */
    public function succeeded(): Collection
    {
        return $this->results->filter(fn (PatchResult $result): bool => $result->succeeded());
    }

    /**
     * @return Collection<int, PatchResult>
     */
    public function failed(): Collection
    {
        return $this->results->filter(fn (PatchResult $result): bool => $result->failed());
    }

    /**
     * @return Collection<int, PatchResult>
     */
    public function skipped(): Collection
    {
        return $this->results->filter(fn (PatchResult $result): bool => $result->skipped());
    }
}
