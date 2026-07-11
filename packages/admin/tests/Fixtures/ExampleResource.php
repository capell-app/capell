<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures;

use Capell\Admin\Filament\Concerns\HasConfiguredForm;
use Capell\Admin\Filament\Concerns\HasConfiguredTable;
use Capell\Admin\Filament\Contracts\FormConfigurator;
use Capell\Admin\Filament\Contracts\TableConfigurator;
use Capell\Admin\Tests\Fixtures\Stubs\ExampleForm;
use Capell\Admin\Tests\Fixtures\Stubs\ExampleTable;
use Capell\Admin\Tests\Fixtures\Stubs\ExampleTrait;
use Filament\Resources\Resource;

class ExampleResource extends Resource
{
    use ExampleTrait;
    use HasConfiguredForm;
    use HasConfiguredTable;

    /**
     * @var class-string<FormConfigurator>
     */
    protected static string $formConfigurator = ExampleForm::class;

    /**
     * @var class-string<TableConfigurator>
     */
    protected static string $tableConfigurator = ExampleTable::class;
}
