<?php

declare(strict_types=1);

use Capell\Core\Support\Patching\PhpFileEditor;
use Capell\Tests\Support\PatchedPhpRuntime;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpParser\Node\Stmt\Declare_;

it('patches namespaced PHP files while preserving class and method discovery', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell_php_editor_');
    file_put_contents($path, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Sample;

use Illuminate\Support\Arr;
use Illuminate\Support\Str as SupportStr;

class Example
{
    public function existing(): array
    {
        return ['total' => (new Collection([1, 2]))->sum(), 'slug' => SupportStr::slug('Hello World'), 'removed_alias' => Arr::class];
    }
}
PHP);

    try {
        $editor = new PhpFileEditor($path);

        expect($editor->findNamespace())->toBe('App\Sample')
            ->and($editor->findClass('App\Sample\Example')?->name?->name)->toBe('Example')
            ->and($editor->findMethodInClass('App\Sample\Example', 'existing')?->name->name)->toBe('existing')
            ->and($editor->findMethodInClass('App\Sample\Example', 'missing'))->toBeNull();

        $editor
            ->addUseStatements([
                Collection::class,
                Arr::class,
            ])
            ->removeUseStatements([
                Arr::class,
            ]);

        $printed = $editor->print();

        $editor->save();
        expect(PatchedPhpRuntime::evaluate($path, '(new App\\Sample\\Example)->existing()'))->toBe([
            'total' => 3, 'slug' => 'hello-world', 'removed_alias' => 'App\\Sample\\Arr',
        ]);
        expect((string) file_get_contents($path))->toBe($printed);

        Date::setTestNow('2026-05-30 12:34:56');
        $backupPath = $editor->backup();

        expect($backupPath)->toEndWith('/2026-05-30-123456/' . basename($path))
            ->and(File::exists($backupPath))->toBeTrue()
            ->and((string) file_get_contents($backupPath))->toBe($printed);
    } finally {
        Date::setTestNow();

        if (isset($backupPath)) {
            File::deleteDirectory(dirname($backupPath));
        }

        if (file_exists($path)) {
            unlink($path);
        }
    }
});

it('patches global PHP files without a namespace', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell_php_editor_global_');
    file_put_contents($path, <<<'PHP'
<?php

declare(strict_types=1);

use Illuminate\Support\Str;

class GlobalExample
{
    public function values(): array
    {
        return ['total' => (new Collection([2, 3]))->sum(), 'removed_alias' => Str::class];
    }
}
PHP);

    try {
        $editor = new PhpFileEditor($path);
        $ast = $editor->getAst();

        expect($editor->findNamespace())->toBeNull()
            ->and($ast[0])->toBeInstanceOf(Declare_::class);

        $editor
            ->addUseStatements([Collection::class])
            ->removeUseStatements([Str::class])
            ->setAst($editor->getAst());

        $editor->save();
        expect(PatchedPhpRuntime::evaluate($path, '(new GlobalExample)->values()'))->toBe(['total' => 5, 'removed_alias' => 'Str']);
    } finally {
        if (file_exists($path)) {
            unlink($path);
        }
    }
});

it('throws when saving a patched php file cannot write to disk', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell_php_editor_readonly_');
    file_put_contents($path, <<<'PHP'
<?php

declare(strict_types=1);

class ReadOnlyExample
{
}
PHP);

    try {
        $editor = new PhpFileEditor($path);
        $original = file_get_contents($path);

        chmod($path, 0400);

        expect(fn (): null => $editor->save())
            ->toThrow(RuntimeException::class, 'Failed to write PHP file at path');

        expect(file_get_contents($path))->toBe($original);
    } finally {
        if (file_exists($path)) {
            chmod($path, 0600);
            unlink($path);
        }
    }
});

it('throws when backing up a php file cannot read the source file', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell_php_editor_unreadable_');
    file_put_contents($path, <<<'PHP'
<?php

declare(strict_types=1);

class UnreadableExample
{
}
PHP);

    try {
        $editor = new PhpFileEditor($path);

        chmod($path, 0200);

        expect(fn (): string => $editor->backup())
            ->toThrow(RuntimeException::class, 'Failed to back up PHP file to path');
    } finally {
        if (file_exists($path)) {
            chmod($path, 0600);
            unlink($path);
        }
    }
});

it('fails clearly for missing or invalid PHP files', function (): void {
    expect(fn (): PhpFileEditor => new PhpFileEditor('/missing/capell/editor.php'))
        ->toThrow(RuntimeException::class, 'File does not exist at path: /missing/capell/editor.php');

    $path = tempnam(sys_get_temp_dir(), 'capell_php_editor_invalid_');
    file_put_contents($path, '<?php class Broken {');

    try {
        expect(fn (): PhpFileEditor => new PhpFileEditor($path))
            ->toThrow(RuntimeException::class, 'Failed to parse PHP file:');
    } finally {
        if (file_exists($path)) {
            unlink($path);
        }
    }
});
