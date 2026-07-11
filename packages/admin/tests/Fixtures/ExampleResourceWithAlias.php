<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures;

use Capell\Admin\Filament\Concerns\HasConfiguredForm as FormConfig;
use stdClass;

class ExampleResourceWithAlias extends stdClass
{
    use FormConfig;

    protected static string $formConfigurator = stdClass::class;
}
