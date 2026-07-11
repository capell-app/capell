<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures;

use Capell\Admin\Filament\Concerns\HasConfiguredForm;
use Capell\Admin\Filament\Contracts\FormConfigurator;
use Capell\Admin\Tests\Fixtures\Stubs\ExampleForm;

class ExampleResourceA extends BaseResourceA implements ExampleInterfaceA
{
    use HasConfiguredForm;

    /**
     * @var class-string<FormConfigurator>
     */
    protected static string $formConfigurator = ExampleForm::class;
}
