<?php

declare(strict_types=1);

use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Actions\ForceDeleteAction;
use Capell\Admin\Filament\Actions\ForceDeleteBulkAction;
use Capell\Admin\Tests\Fixtures\DeletionPackage\src\Filament\Resources\Unsafe\UnsafeDeletionResource;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Page;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Illuminate\Support\Facades\DB;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * This source guard resolves literal methods, classes and DB table names. It cannot
 * resolve dynamic method/class/table names, or macros/traits outside the scanned trees.
 * Runtime authorisation and dependency regressions remain necessary alongside it.
 *
 * @return list<string>
 */
function unguardedResourceDeletionActions(string $source): array
{
    $parser = new ParserFactory()->createForNewestSupportedVersion();
    $traverser = new NodeTraverser(new NameResolver);
    $nodes = $traverser->traverse($parser->parse($source) ?? []);
    $violations = [];

    foreach ((new NodeFinder)->findInstanceOf($nodes, Node::class) as $node) {
        if (($node instanceof MethodCall || $node instanceof NullsafeMethodCall || $node instanceof StaticCall)
            && $node->name instanceof Identifier && in_array(strtolower($node->name->toString()), ['forcedelete', 'forcedeletequietly', 'forcedestroy'], true)) {
            $violations[] = 'Direct permanent deletion bypasses the guarded action.';
        }

        if (($node instanceof MethodCall || $node instanceof NullsafeMethodCall) && $node->name instanceof Identifier && strtolower($node->name->toString()) === 'delete') {
            $query = $node->var;
            $tableName = null;
            while ($query instanceof MethodCall || $query instanceof NullsafeMethodCall) {
                if ($tableName === null && $query->name instanceof Identifier && in_array(strtolower($query->name->toString()), ['table', 'from'], true)
                    && ($query->args[0]->value ?? null) instanceof String_) {
                    $tableName = $query->args[0]->value->value;
                }

                $query = $query->var;
            }

            if ($query instanceof StaticCall && $query->class instanceof Name && $query->class->toString() === DB::class
                && $query->name instanceof Identifier && in_array(strtolower($query->name->toString()), ['table', 'connection', 'query'], true)) {
                if ($tableName === null && strtolower($query->name->toString()) === 'table' && ($query->args[0]->value ?? null) instanceof String_) {
                    $tableName = $query->args[0]->value->value;
                }

                if ($tableName === null) {
                    continue;
                }

                $tables = array_map(fn (ResourceEnum $resource): string => (new ($resource->value::getModel()))->getTable(), ResourceEnum::cases());
                $tables[] = 'site_domains';
                $table = preg_split('/\s+/u', strtolower($tableName))[0] ?? '';
                if (in_array($table, $tables, true)) {
                    $violations[] = 'Direct resource table deletion bypasses the guarded action: ' . $table;
                }
            }
        }

        $class = match (true) {
            $node instanceof StaticCall, $node instanceof New_ => $node->class,
            $node instanceof Class_ => $node->extends,
            default => null,
        };
        if (! $class instanceof Name) {
            continue;
        }

        $name = $class->toString();
        if ((is_a($name, Filament\Actions\ForceDeleteAction::class, true) || is_a($name, Filament\Actions\ForceDeleteBulkAction::class, true))
            && ! in_array($name, [ForceDeleteAction::class, ForceDeleteBulkAction::class], true)) {
            $violations[] = $name;
        }
    }

    return $violations;
}

/**
 * Scan this foundation's Filament trees and local Admin registry contributions.
 * Companion packages own their source guards; real paths keep symlinks from escaping.
 *
 * @return list<string>
 */
function resourceDeletionSourceFiles(): array
{
    $foundationSources = realpath(dirname(__DIR__, 5) . '/packages');
    assert(is_string($foundationSources));
    $directories = [realpath(__DIR__ . '/../../../src/Filament')];
    foreach (CapellCore::getInstalledPackages() as $package) {
        if (is_string($package->path)) {
            $directories[] = $package->path . '/src/Filament';
        }
    }

    foreach (CapellAdmin::getAdminSurfaceRegistry()->all() as $contributions) {
        foreach ($contributions as $contribution) {
            if (! class_exists($contribution->class)) {
                continue;
            }

            $file = new ReflectionClass($contribution->class)->getFileName();
            if (! is_string($file)) {
                continue;
            }

            $directories[] = dirname($file);
            $directory = dirname($file);
            while (dirname($directory) !== $directory) {
                if (basename($directory) === 'Filament') {
                    $directories[] = $directory;
                    break;
                }

                $directory = dirname($directory);
            }
        }
    }

    $files = [];
    foreach (array_unique($directories) as $directory) {
        if (! is_string($directory)) {
            continue;
        }

        $directory = realpath($directory);
        if (! is_string($directory)) {
            continue;
        }

        if (! str_starts_with($directory, $foundationSources . DIRECTORY_SEPARATOR)) {
            continue;
        }

        if (! is_dir($directory)) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $path = $file->getRealPath();
                if (is_string($path) && str_starts_with($path, $foundationSources . DIRECTORY_SEPARATOR)) {
                    $files[] = $path;
                }
            }
        }
    }

    return array_values(array_unique($files));
}

it('scans table and page sources beside registered package resources', function (): void {
    CapellAdmin::getAdminSurfaceRegistry()->register(AdminSurfaceContributionData::resource(UnsafeDeletionResource::class, 'Unsafe'));
    $path = __DIR__ . '/../../Fixtures/DeletionPackage/src/Filament/Resources/Unsafe/UnsafeDeletionTable.php';
    expect(array_map(realpath(...), resourceDeletionSourceFiles()))->toContain(realpath($path));
    expect(unguardedResourceDeletionActions((string) file_get_contents($path)))->not->toBeEmpty();
});

it('scans installed-package Filament sources even without prior resource registration', function (): void {
    CapellCore::partialMock()->shouldReceive('getInstalledPackages')->andReturn(collect([
        new PackageData(name: 'vendor/deletion-fixture', type: PackageTypeEnum::Package, path: __DIR__ . '/../../Fixtures/DeletionPackage'),
    ]));
    $path = __DIR__ . '/../../Fixtures/DeletionPackage/src/Filament/Resources/Unsafe/UnsafeDeletionTable.php';
    expect(array_map(realpath(...), resourceDeletionSourceFiles()))->toContain(realpath($path));
    expect(unguardedResourceDeletionActions((string) file_get_contents($path)))->not->toBeEmpty();
});

it('discovers every resource deletion and restoration action and rejects unguarded permanent deletion', function (): void {
    $finder = new NodeFinder;
    $parser = new ParserFactory()->createForNewestSupportedVersion();
    $deletionActions = [];
    $unexpected = [];
    $reported = [];

    foreach (resourceDeletionSourceFiles() as $path) {
        $file = new SplFileInfo($path);

        if (in_array($file->getRealPath(), [new ReflectionClass(ForceDeleteAction::class)->getFileName(), new ReflectionClass(ForceDeleteBulkAction::class)->getFileName()], true)) {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        expect($source)->toBeString();
        assert(is_string($source));
        $actual = unguardedResourceDeletionActions($source);
        if ($actual !== []) {
            $unexpected[$file->getPathname()] = $actual;
            $reported[$file->getPathname()] = $actual;
        }

        $nodes = new NodeTraverser(new NameResolver)->traverse($parser->parse($source) ?? []);
        foreach ($finder->findInstanceOf($nodes, StaticCall::class) as $call) {
            if (! $call->class instanceof Name) {
                continue;
            }

            $name = $call->class->toString();
            foreach ([DeleteAction::class, DeleteBulkAction::class, ForceDeleteAction::class, ForceDeleteBulkAction::class, RestoreAction::class, RestoreBulkAction::class] as $action) {
                if (is_a($name, $action, true)) {
                    $deletionActions[$action][] = $file->getPathname();
                }
            }
        }
    }

    $report = getenv('CAPELL_DELETION_GUARD_REPORT');
    if (is_string($report) && $report !== '') {
        file_put_contents($report, json_encode($reported, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    expect($unexpected)->toBe([]);

    foreach ([DeleteAction::class, DeleteBulkAction::class, ForceDeleteAction::class, ForceDeleteBulkAction::class, RestoreAction::class, RestoreBulkAction::class] as $action) {
        expect($deletionActions[$action] ?? [])->not->toBeEmpty();
    }
});

it('rejects aliases, fully qualified construction, subclasses and direct permanent deletion', function (string $source): void {
    expect(unguardedResourceDeletionActions('<?php ' . $source))->not->toBeEmpty();
})->with([
    'alias' => 'use Filament\\Actions\\ForceDeleteBulkAction as Remove; Remove::make();',
    'qualified' => Filament\Actions\ForceDeleteAction::class . '::make();',
    'constructor' => 'new \\Filament\\Actions\\ForceDeleteAction("remove");',
    'subclass' => 'class Unsafe extends \\Filament\\Actions\\ForceDeleteBulkAction {}',
    'direct' => '$record->forceDelete();',
    'nullsafe' => '$record?->forceDelete();',
    'static deletion' => Page::class . '::forceDelete();',
    'quiet deletion' => '$record->forceDeleteQuietly();',
    'destroy' => Page::class . '::forceDestroy([1, 2]);',
    'raw resource query' => 'use Illuminate\\Support\\Facades\\DB; DB::table("pages")->where("id", 1)->delete();',
    'connection query' => 'use Illuminate\\Support\\Facades\\DB; DB::connection()->table("pages")->delete();',
    'named connection query' => 'use Illuminate\\Support\\Facades\\DB; DB::connection("sqlite")->table("pages")->where("id", 1)->delete();',
    'aliased connection query' => 'use Illuminate\\Support\\Facades\\DB as Database; Database::connection("sqlite")->table("layouts as l")->delete();',
    'nullsafe connection query' => 'use Illuminate\\Support\\Facades\\DB; DB::connection("sqlite")->table("pages")?->delete();',
    'connection builder from' => 'use Illuminate\\Support\\Facades\\DB; DB::connection()->query()->from("pages")->delete();',
    'named connection builder from' => 'use Illuminate\\Support\\Facades\\DB; DB::connection("sqlite")->query()->from("pages")->where("id", 1)->delete();',
    'aliased connection builder from' => 'use Illuminate\\Support\\Facades\\DB as Database; Database::connection("sqlite")->query()->from("layouts", "l")->delete();',
    'nullsafe connection builder from' => 'use Illuminate\\Support\\Facades\\DB; DB::connection("sqlite")->query()->from("pages")?->delete();',
    'connection aliased from' => 'use Illuminate\\Support\\Facades\\DB; DB::connection()->query()->from("site_domains as d")->delete();',
    'facade builder from' => 'use Illuminate\\Support\\Facades\\DB; DB::query()->from("pages")->delete();',
    'raw aliased query' => 'use Illuminate\\Support\\Facades\\DB as Database; Database::table("layouts")->delete();',
]);

it('keeps confirmation and model fetching mandatory when callers override action configuration', function (): void {
    expect(ForceDeleteAction::make()->requiresConfirmation(false)->isConfirmationRequired())->toBeTrue()
        ->and(ForceDeleteBulkAction::make()->requiresConfirmation(false)->isConfirmationRequired())->toBeTrue()
        ->and(ForceDeleteBulkAction::make()->fetchSelectedRecords(false)->shouldFetchSelectedRecords())->toBeTrue()
        ->and(new ReflectionClass(ForceDeleteAction::class)->isFinal())->toBeTrue()
        ->and(new ReflectionClass(ForceDeleteBulkAction::class)->isFinal())->toBeTrue();
});

it('permits deletion of unrelated operational tables', function (): void {
    expect(unguardedResourceDeletionActions('<?php use Illuminate\\Support\\Facades\\DB; DB::table("jobs")->delete();'))->toBe([])
        ->and(unguardedResourceDeletionActions('<?php use Illuminate\\Support\\Facades\\DB; DB::connection("sqlite")->table("jobs")->delete();'))->toBe([])
        ->and(unguardedResourceDeletionActions('<?php use Illuminate\\Support\\Facades\\DB; DB::connection("sqlite")->query()->from("jobs")->delete();'))->toBe([])
        ->and(unguardedResourceDeletionActions('<?php use Illuminate\\Support\\Facades\\DB; DB::query()->from("jobs")->delete();'))->toBe([]);
});

it('confines discovered sources to foundation packages even with other installed paths', function (bool $insideRepository): void {
    $parent = $insideRepository ? dirname(__DIR__, 5) . '/var/codex' : sys_get_temp_dir();
    $external = $parent . '/capell-deletion-discovery-' . bin2hex(random_bytes(8));
    mkdir($external . '/src/Filament', 0777, true);
    $specimen = $external . '/src/Filament/External.php';
    file_put_contents($specimen, '<?php $record->forceDelete();');
    CapellCore::partialMock()->shouldReceive('getInstalledPackages')->andReturn(collect([
        new PackageData(name: 'vendor/external-specimen', type: PackageTypeEnum::Package, path: $external),
    ]));
    try {
        expect(resourceDeletionSourceFiles())->not->toContain(realpath($specimen));
        foreach (resourceDeletionSourceFiles() as $file) {
            expect(str_starts_with($file, dirname(__DIR__, 5) . '/packages/'))->toBeTrue();
        }

        expect((string) file_get_contents(__FILE__))->not->toContain("getenv('" . 'CAPELL_DELETION_GUARD_PACKAGE_PATHS' . "')");
    } finally {
        unlink($specimen);
        rmdir($external . '/src/Filament');
        rmdir($external . '/src');
        rmdir($external);
    }
})->with(['external root' => false, 'non-foundation root in this checkout' => true]);
