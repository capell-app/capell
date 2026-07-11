<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Enums\LayoutEnum;
use Capell\Core\Models\Layout;
use Capell\Core\Support\Creator\LayoutCreator;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static Layout run()
 */
final class GetOrCreateResultsLayoutAction
{
    use AsObject;

    public function handle(): Layout
    {
        return Layout::query()->firstWhere('key', LayoutEnum::Results->value)
            ?? resolve(LayoutCreator::class)->create(LayoutEnum::Results);
    }
}
