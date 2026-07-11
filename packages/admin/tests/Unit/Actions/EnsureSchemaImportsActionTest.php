<?php

declare(strict_types=1);

use Capell\Admin\Actions\EnsureSchemaImportsAction;
use Capell\Admin\Tests\Fixtures\SchemaImports\SourceSchema;

it('adds imports for parent interfaces and traits from the original schema namespace', function (): void {
    $content = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Schemas;

class SourceSchema extends BaseSchema implements SchemaContract
{
    use HasSchemaBehavior;
}
PHP;

    $result = EnsureSchemaImportsAction::run(
        content: $content,
        reflector: new ReflectionClass(SourceSchema::class),
        originalNamespace: 'Capell\\Admin\\Tests\\Fixtures\\SchemaImports',
    );

    expect($result)->toContain('use Capell\\Admin\\Tests\\Fixtures\\SchemaImports\\Concerns\\BaseSchema;')
        ->and($result)->toContain('use Capell\\Admin\\Tests\\Fixtures\\SchemaImports\\Contracts\\SchemaContract;')
        ->and($result)->toContain('use Capell\\Admin\\Tests\\Fixtures\\SchemaImports\\Concerns\\HasSchemaBehavior;')
        ->and(substr_count($result, 'use Capell\\Admin\\Tests\\Fixtures\\SchemaImports'))->toBe(3);
});

it('keeps existing imports and aliases basename collisions', function (): void {
    $content = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Schemas;

use Capell\Admin\Tests\Fixtures\SchemaImports\Legacy\BaseSchema;

class SourceSchema extends BaseSchema implements SchemaContract
{
    use HasSchemaBehavior;
}
PHP;

    $result = EnsureSchemaImportsAction::run(
        content: $content,
        reflector: new ReflectionClass(SourceSchema::class),
        originalNamespace: 'Capell\\Admin\\Tests\\Fixtures\\SchemaImports',
    );

    expect($result)->toContain('use Capell\\Admin\\Tests\\Fixtures\\SchemaImports\\Legacy\\BaseSchema;')
        ->and($result)->toContain('use Capell\\Admin\\Tests\\Fixtures\\SchemaImports\\Concerns\\BaseSchema as ConcernsBaseSchema;')
        ->and($result)->toContain('use Capell\\Admin\\Tests\\Fixtures\\SchemaImports\\Contracts\\SchemaContract;')
        ->and($result)->toContain('use Capell\\Admin\\Tests\\Fixtures\\SchemaImports\\Concerns\\HasSchemaBehavior;');
});

it('does not duplicate imports that already exist', function (): void {
    $content = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Schemas;

use Capell\Admin\Tests\Fixtures\SchemaImports\Concerns\BaseSchema;
use Capell\Admin\Tests\Fixtures\SchemaImports\Contracts\SchemaContract;
use Capell\Admin\Tests\Fixtures\SchemaImports\Concerns\HasSchemaBehavior;

class SourceSchema extends BaseSchema implements SchemaContract
{
    use HasSchemaBehavior;
}
PHP;

    $result = EnsureSchemaImportsAction::run(
        content: $content,
        reflector: new ReflectionClass(SourceSchema::class),
        originalNamespace: 'Capell\\Admin\\Tests\\Fixtures\\SchemaImports',
    );

    expect(substr_count($result, 'use Capell\\Admin\\Tests\\Fixtures\\SchemaImports\\Concerns\\BaseSchema;'))->toBe(1)
        ->and(substr_count($result, 'use Capell\\Admin\\Tests\\Fixtures\\SchemaImports\\Contracts\\SchemaContract;'))->toBe(1)
        ->and(substr_count($result, 'use Capell\\Admin\\Tests\\Fixtures\\SchemaImports\\Concerns\\HasSchemaBehavior;'))->toBe(1);
});
