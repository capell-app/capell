<?php

declare(strict_types=1);

namespace Capell\Admin\Data;

use Capell\Admin\Filament\Contracts\FormConfigurator;
use Capell\Core\Contracts\Actionable;
use Spatie\LaravelData\Data;

class AdminAssetData extends Data
{
    public function __construct(
        /** @var class-string<FormConfigurator> $formClass */
        public string $formClass,
        /** @var ?class-string $createAction */
        public ?string $createAction = null,
        /** @var ?class-string<Actionable> $defaultDataAction */
        public ?string $defaultDataAction = null,
    ) {}
}
