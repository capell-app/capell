<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\Blueprint;
use Capell\Core\Support\Creator\BlueprintCreator;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class CreateDefaultPageBlueprintAction
{
    use AsFake;
    use AsObject;

    public function handle(): Blueprint
    {
        return resolve(BlueprintCreator::class)->defaultPageType();
    }
}
