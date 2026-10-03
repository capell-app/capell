<?php

declare(strict_types=1);

use Capell\Admin\Filament\Actions\ForceDeleteAction;
use Capell\Admin\Filament\Actions\ForceDeleteBulkAction;
use Capell\Core\Models\Page;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** @return list<string> */
function unguardedResourceDeletionActions(string $source): array
{
    $parser = new ParserFactory()->createForNewestSupportedVersion();
    $traverser = new NodeTraverser(new NameResolver);
    $nodes = $traverser->traverse($parser->parse($source) ?? []);
    $violations = [];

    foreach ((new NodeFinder)->findInstanceOf($nodes, Node::class) as $node) {
        if (($node instanceof MethodCall || $node instanceof NullsafeMethodCall || $node instanceof StaticCall)
            && $node->name instanceof Identifier && $node->name->toString() === 'forceDelete') {
            $violations[] = 'Direct permanent deletion bypasses the guarded action.';
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

it('discovers every resource deletion and restoration action and rejects unguarded permanent deletion', function (): void {
    $directory = new RecursiveDirectoryIterator(__DIR__ . '/../../../src/Filament');
    $finder = new NodeFinder;
    $parser = new ParserFactory()->createForNewestSupportedVersion();
    $deletionActions = [];

    foreach (new RecursiveIteratorIterator($directory) as $file) {
        if (! $file instanceof SplFileInfo) {
            continue;
        }

        if ($file->getExtension() !== 'php') {
            continue;
        }

        if (in_array($file->getRealPath(), [new ReflectionClass(ForceDeleteAction::class)->getFileName(), new ReflectionClass(ForceDeleteBulkAction::class)->getFileName()], true)) {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        expect($source)->toBeString();
        assert(is_string($source));
        expect(unguardedResourceDeletionActions($source))->toBe([], $file->getPathname());

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
]);

it('keeps confirmation and model fetching mandatory when callers override action configuration', function (): void {
    expect(ForceDeleteAction::make()->requiresConfirmation(false)->isConfirmationRequired())->toBeTrue()
        ->and(ForceDeleteBulkAction::make()->requiresConfirmation(false)->isConfirmationRequired())->toBeTrue()
        ->and(ForceDeleteBulkAction::make()->fetchSelectedRecords(false)->shouldFetchSelectedRecords())->toBeTrue()
        ->and(new ReflectionClass(ForceDeleteAction::class)->isFinal())->toBeTrue()
        ->and(new ReflectionClass(ForceDeleteBulkAction::class)->isFinal())->toBeTrue();
});
