<?php

declare(strict_types=1);

namespace Capell\Core\Models\Contracts;

use Capell\Core\Enums\PublishStatusEnum;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Carbon\CarbonImmutable;

interface Publishable
{
    /** @return bool */
    public function trashed();

    public function isExpired(): bool;

    public function isPending(): bool;

    /**
     * Returns the current publish status for the model.
     */
    public function getPublishStatus(): PublishStatusEnum;

    public function publishVisibilityState(?CarbonImmutable $now = null): PublishVisibilityStateEnum;
}
