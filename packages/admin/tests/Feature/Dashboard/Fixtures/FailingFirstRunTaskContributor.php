<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Dashboard\Fixtures;

use Capell\Admin\Contracts\Dashboard\FirstRunTaskContributor;
use Capell\Admin\Data\Dashboard\FirstRunTaskContextData;
use RuntimeException;

final class FailingFirstRunTaskContributor implements FirstRunTaskContributor
{
    public function tasks(FirstRunTaskContextData $context): array
    {
        throw new RuntimeException('Contributor could not reach its data.');
    }
}
