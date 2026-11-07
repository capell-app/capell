<?php

declare(strict_types=1);

use Capell\Tests\Support\Psr4TestDeclarationScanner;

it('keeps named test declarations autoloadable through their Composer PSR-4 mappings', function (): void {
    $violations = new Psr4TestDeclarationScanner()->scan(dirname(__DIR__, 4));
    $diagnostics = array_map(static fn (array $violation): string => $violation['file'] . ':' . $violation['line'] . ':' . $violation['class'], $violations);

    expect($diagnostics)->toBe([], "PSR-4 test declaration violations:\n" . implode("\n", $diagnostics));
})->group('Core');

it('discovers named declarations without confusing anonymous classes, class constants or fixture strings', function (): void {
    $source = <<<'PHP'
<?php
namespace Example\Tests {
    #[SomeAttribute]
    final readonly class NamedClass {}
    interface NamedInterface {}
    trait NamedTrait {}
    enum NamedEnum { case First; }
    $anonymous = new #[SomeAttribute] class(NamedClass::class) {};
    $readonly = new readonly class {};
    $text = "{$anonymous->value}";
    $fixture = <<<'FIXTURE'
class NotADeclaration {}
FIXTURE;
    class AfterInterpolation {}
}
namespace Other\Tests { class OtherClass {} }
namespace { class GlobalClass {} }
PHP;

    $declarations = new Psr4TestDeclarationScanner()->declarations($source);

    expect(array_column($declarations, 'class'))->toBe([
        'Example\\Tests\\NamedClass',
        'Example\\Tests\\NamedInterface',
        'Example\\Tests\\NamedTrait',
        'Example\\Tests\\NamedEnum',
        'Example\\Tests\\AfterInterpolation',
        'Other\\Tests\\OtherClass',
        'GlobalClass',
    ]);
})->group('Core');

it('derives root and package mappings including multiple directories and reports each violation once', function (): void {
    $root = sys_get_temp_dir() . '/capell-test-psr4-' . bin2hex(random_bytes(8));
    $files = [
        'composer.json' => json_encode(['autoload-dev' => ['psr-4' => ['Example\\Tests\\' => ['tests', 'extra'], 'Example\\Package\\Tests\\' => 'packages/example/tests']]], JSON_THROW_ON_ERROR),
        'packages/example/composer.json' => json_encode(['autoload-dev' => ['psr-4' => ['Example\\Package\\Tests\\' => 'tests']]], JSON_THROW_ON_ERROR),
        'tests/Correct.php' => '<?php namespace Example\\Tests; class Correct {}',
        'extra/AlsoCorrect.php' => '<?php namespace Example\\Tests; interface AlsoCorrect {}',
        'tests/Wrong.php' => '<?php namespace Example\\Tests; trait Misplaced {}',
        'packages/example/tests/Wrong.php' => '<?php namespace Example\\Package\\Tests; enum Misplaced { case First; }',
    ];

    try {
        foreach ($files as $path => $contents) {
            $directory = dirname($root . '/' . $path);
            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            file_put_contents($root . '/' . $path, $contents);
        }

        $violations = new Psr4TestDeclarationScanner()->scan($root);

        expect(array_column($violations, 'file'))->toBe(['packages/example/tests/Wrong.php', 'tests/Wrong.php'])
            ->and(array_column($violations, 'class'))->toBe(['Example\\Package\\Tests\\Misplaced', 'Example\\Tests\\Misplaced'])
            ->and($violations[1]['expected'])->toBe(['tests/Misplaced.php', 'extra/Misplaced.php']);
    } finally {
        foreach (array_keys($files) as $path) {
            if (is_file($root . '/' . $path)) {
                unlink($root . '/' . $path);
            }
        }

        foreach (['packages/example/tests', 'packages/example', 'packages', 'extra', 'tests', ''] as $directory) {
            if (is_dir($root . '/' . $directory)) {
                rmdir($root . '/' . $directory);
            }
        }
    }
})->group('Core');

it('ignores hidden descendants while retaining ordinary root and package fixture violations', function (): void {
    $root = sys_get_temp_dir() . '/capell-test-psr4-hidden-' . bin2hex(random_bytes(8));
    $files = [
        'composer.json' => json_encode(['autoload-dev' => ['psr-4' => ['Example\\Tests\\' => 'tests']]], JSON_THROW_ON_ERROR),
        'packages/example/composer.json' => json_encode(['autoload-dev' => ['psr-4' => ['Example\\Package\\Tests\\' => 'tests']]], JSON_THROW_ON_ERROR),
        'tests/Correct.php' => '<?php namespace Example\\Tests; class Correct {}',
        'packages/example/tests/Correct.php' => '<?php namespace Example\\Package\\Tests; class Correct {}',
        'tests/.pest/testbench-skeletons/app/Generated.php' => '<?php namespace App; class Generated {}',
        'tests/.pest/testbench-skeletons/database/migrations/Generated.php' => '<?php class CreateGeneratedTables {}',
        'packages/example/tests/.pest/testbench-skeletons/app/Generated.php' => '<?php namespace PackageApp; class Generated {}',
        'packages/example/tests/.pest/testbench-skeletons/database/migrations/Generated.php' => '<?php class CreatePackageGeneratedTables {}',
        'tests/.hidden.php' => '<?php namespace App; class HiddenFile {}',
        'packages/example/tests/.hidden.php' => '<?php namespace PackageApp; class HiddenFile {}',
        'tests/fixtures/ForeignFixture.php' => '<?php namespace Example\\Tests; class MisplacedFixture {}',
        'packages/example/tests/fixtures/ForeignFixture.php' => '<?php namespace Example\\Package\\Tests; class MisplacedFixture {}',
    ];

    try {
        foreach ($files as $path => $contents) {
            $directory = dirname($root . '/' . $path);
            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            file_put_contents($root . '/' . $path, $contents);
        }

        expect(new Psr4TestDeclarationScanner()->scan($root))->toBe([
            [
                'file' => 'packages/example/tests/fixtures/ForeignFixture.php',
                'line' => 1,
                'class' => 'Example\\Package\\Tests\\MisplacedFixture',
                'expected' => ['packages/example/tests/MisplacedFixture.php'],
            ],
            [
                'file' => 'tests/fixtures/ForeignFixture.php',
                'line' => 1,
                'class' => 'Example\\Tests\\MisplacedFixture',
                'expected' => ['tests/MisplacedFixture.php'],
            ],
        ]);
    } finally {
        if (is_dir($root)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }

            rmdir($root);
        }
    }
})->group('Core');
