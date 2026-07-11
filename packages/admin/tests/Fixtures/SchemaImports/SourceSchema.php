<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\SchemaImports;

use Capell\Admin\Tests\Fixtures\SchemaImports\Concerns\BaseSchema;
use Capell\Admin\Tests\Fixtures\SchemaImports\Concerns\HasSchemaBehavior;
use Capell\Admin\Tests\Fixtures\SchemaImports\Contracts\SchemaContract;

class SourceSchema extends BaseSchema implements SchemaContract
{
    use HasSchemaBehavior;
}
