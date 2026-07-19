<?php

declare(strict_types=1);

namespace Capell\Tests\Support\Octane;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;

final class SingletonLifetimeGuard
{
    private readonly Parser $parser;

    /** @param array<string, string> $dynamicBindingTargets */
    public function __construct(
        private readonly string $packagesPath,
        private readonly array $dynamicBindingTargets = [],
    ) {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    /** @return array<string, array{file: string, abstract: string}> */
    public function singletonTargets(): array
    {
        return $this->bindingTargets(['singleton', 'singletonIf']);
    }

    /** @return array<string, array{file: string, abstract: string}> */
    public function scopedTargets(): array
    {
        return $this->bindingTargets(['scoped']);
    }

    /** @return array<string, string> */
    public function unresolvedClosureBindings(): array
    {
        $unresolved = [];

        foreach ($this->productionFiles() as $file) {
            foreach ($this->bindingCalls($this->parse((string) file_get_contents($file)), ['singleton', 'singletonIf', 'scoped']) as $call) {
                $concrete = $call->args[1]->value ?? null;

                if ((! $concrete instanceof Node\Expr\Closure && ! $concrete instanceof Node\Expr\ArrowFunction)
                    || $this->bindingTarget($call) !== null) {
                    continue;
                }

                $abstract = $this->className($call->args[0]->value ?? null);

                if ($abstract !== null) {
                    $unresolved[$abstract] = $file;
                }
            }
        }

        ksort($unresolved);

        return $unresolved;
    }

    /** @return array<string, true> */
    public function resettableTaggedTargets(string $resettableClass): array
    {
        $targets = [];

        foreach ($this->productionFiles() as $file) {
            $nodes = $this->parse((string) file_get_contents($file));

            foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Expr\MethodCall::class) as $call) {
                if (! $call->name instanceof Node\Identifier || $call->name->toString() !== 'tag') {
                    continue;
                }

                $tag = $call->args[1]->value ?? null;
                $services = $call->args[0]->value ?? null;

                if ($this->classConstOwner($tag) !== $resettableClass || ! $services instanceof Node\Expr\Array_) {
                    continue;
                }

                foreach ($services->items as $item) {
                    $target = $this->className($item?->value);

                    if ($target !== null) {
                        $targets[$target] = true;
                    }
                }
            }
        }

        return $targets;
    }

    /** @return array<string, string> */
    public function mutatedStaticState(): array
    {
        $hazards = [];

        foreach ($this->productionFiles() as $file) {
            foreach ($this->staticMutationsInSource((string) file_get_contents($file)) as $class) {
                $hazards[$class] = $file;
            }
        }

        ksort($hazards);

        return $hazards;
    }

    /** @return list<string> */
    public function staticMutationsInSource(string $source): array
    {
        // This bounded guard recognizes direct assignment (including array
        // dimensions), assign-op, increment/decrement, unset, and method calls
        // whose receiver is the static property itself. Mutation through a
        // local alias or an arbitrary function cannot be inferred reliably;
        // reflection of every bound concrete plus the exact inventory covers
        // the service-lifetime side of that boundary.
        $nodes = $this->parse($source);
        $finder = new NodeFinder;
        $declared = [];

        /** @var list<Node\Stmt\ClassLike> $classLikes */
        $classLikes = $finder->find($nodes, static fn (Node $node): bool => $node instanceof Node\Stmt\ClassLike && $node->name !== null);

        foreach ($classLikes as $classLike) {
            $class = $classLike->namespacedName?->toString();

            if ($class === null) {
                continue;
            }

            foreach ($classLike->getProperties() as $property) {
                if (! $property->isStatic()) {
                    continue;
                }

                foreach ($property->props as $propertyItem) {
                    $declared[$class][$propertyItem->name->toString()] = true;
                }
            }
        }

        $mutated = [];

        foreach ($classLikes as $classLike) {
            $scope = $classLike->namespacedName?->toString();

            if ($scope === null) {
                continue;
            }

            $mutationRoots = [];
            $mutationRoots = [...$mutationRoots, ...array_map(static fn (Node\Expr\Assign|Node\Expr\AssignOp $node): Node\Expr => $node->var, $finder->find(
                $classLike->stmts,
                static fn (Node $node): bool => $node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp,
            ))];
            $mutationRoots = [...$mutationRoots, ...array_map(static fn (Node\Expr\PreInc|Node\Expr\PostInc|Node\Expr\PreDec|Node\Expr\PostDec $node): Node\Expr => $node->var, $finder->find(
                $classLike->stmts,
                static fn (Node $node): bool => $node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PreDec || $node instanceof Node\Expr\PostDec,
            ))];

            foreach ($finder->findInstanceOf($classLike->stmts, Node\Stmt\Unset_::class) as $unset) {
                $mutationRoots = [...$mutationRoots, ...$unset->vars];
            }

            foreach ($finder->findInstanceOf($classLike->stmts, Node\Expr\MethodCall::class) as $methodCall) {
                $mutationRoots[] = $methodCall->var;
            }

            foreach ($mutationRoots as $root) {
                $fetch = $this->staticPropertyRoot($root);

                if (! $fetch instanceof Node\Expr\StaticPropertyFetch || ! $fetch->name instanceof Node\VarLikeIdentifier) {
                    continue;
                }

                $owner = $this->staticOwner($fetch, $scope);
                $property = $fetch->name->toString();

                if ($owner !== null && isset($declared[$owner][$property])) {
                    $mutated[$owner] = true;
                }
            }
        }

        return array_keys($mutated);
    }

    /** @return list<string> */
    public function mutableInstanceState(string $class): array
    {
        if (! class_exists($class)) {
            return [];
        }

        return array_values(array_unique($this->mutableStateFor(new ReflectionClass($class), [])));
    }

    /** @return list<string> */
    public function bindingTargetsInSource(string $source, array $methods = ['singleton', 'singletonIf']): array
    {
        $targets = [];

        foreach ($this->bindingCalls($this->parse($source), $methods) as $call) {
            $target = $this->bindingTarget($call) ?? $this->className($call->args[0]->value ?? null);

            if ($target !== null) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    /** @return array<string, array{file: string, abstract: string}> */
    private function bindingTargets(array $methods): array
    {
        $targets = [];

        foreach ($this->productionFiles() as $file) {
            foreach ($this->bindingCalls($this->parse((string) file_get_contents($file)), $methods) as $call) {
                $abstract = $this->className($call->args[0]->value ?? null);
                $target = $this->bindingTarget($call)
                    ?? ($abstract !== null ? ($this->dynamicBindingTargets[$abstract] ?? $abstract) : null);

                if ($target === null || ! str_starts_with($target, 'Capell\\')) {
                    continue;
                }

                $targets[$target] = ['file' => $file, 'abstract' => $abstract ?? $target];
            }
        }

        ksort($targets);

        return $targets;
    }

    /** @return list<Node\Expr\MethodCall> */
    private function bindingCalls(array $nodes, array $methods): array
    {
        $calls = (new NodeFinder)->find(
            $nodes,
            static fn (Node $node): bool => $node instanceof Node\Expr\MethodCall
                && $node->name instanceof Node\Identifier
                && in_array($node->name->toString(), $methods, true),
        );

        return array_values(array_filter(
            $calls,
            static fn (Node $node): bool => $node instanceof Node\Expr\MethodCall,
        ));
    }

    private function bindingTarget(Node\Expr\MethodCall $call): ?string
    {
        $concrete = $call->args[1]->value ?? null;

        if (! $concrete instanceof Node\Expr\Closure && ! $concrete instanceof Node\Expr\ArrowFunction) {
            return $this->className($concrete);
        }

        if ($concrete instanceof Node\Expr\ArrowFunction) {
            return $concrete->expr instanceof Node\Expr\New_ ? $this->newClassName($concrete->expr) : null;
        }

        foreach ($concrete->stmts as $statement) {
            if ($statement instanceof Node\Stmt\Return_ && $statement->expr instanceof Node\Expr\New_) {
                return $this->newClassName($statement->expr);
            }
        }

        return null;
    }

    private function className(?Node $node): ?string
    {
        if (! $node instanceof Node\Expr\ClassConstFetch
            || ! $node->name instanceof Node\Identifier
            || $node->name->toString() !== 'class'
            || ! $node->class instanceof Node\Name) {
            return null;
        }

        return $node->class->toString();
    }

    private function classConstOwner(?Node $node): ?string
    {
        return $node instanceof Node\Expr\ClassConstFetch && $node->class instanceof Node\Name
            ? $node->class->toString()
            : null;
    }

    private function newClassName(Node\Expr\New_ $new): ?string
    {
        return $new->class instanceof Node\Name ? $new->class->toString() : null;
    }

    /** @return list<string> */
    private function productionFiles(): array
    {
        $files = [];

        foreach (glob($this->packagesPath . '/*/src', GLOB_ONLYDIR) ?: [] as $sourcePath) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourcePath));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    /** @return array<Node> */
    private function parse(string $source): array
    {
        $nodes = $this->parser->parse($source) ?? [];
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);

        return $traverser->traverse($nodes);
    }

    private function staticPropertyRoot(Node\Expr $expression): ?Node\Expr\StaticPropertyFetch
    {
        while ($expression instanceof Node\Expr\ArrayDimFetch) {
            $expression = $expression->var;
        }

        return $expression instanceof Node\Expr\StaticPropertyFetch ? $expression : null;
    }

    private function staticOwner(Node\Expr\StaticPropertyFetch $fetch, string $scope): ?string
    {
        if (! $fetch->class instanceof Node\Name) {
            return null;
        }

        return in_array(strtolower($fetch->class->toString()), ['self', 'static'], true)
            ? $scope
            : $fetch->class->toString();
    }

    /** @param array<string, true> $visited @return list<string> */
    private function mutableStateFor(ReflectionClass $class, array $visited): array
    {
        if (isset($visited[$class->getName()])) {
            return [];
        }

        $visited[$class->getName()] = true;
        $state = [];

        for ($current = $class; $current instanceof ReflectionClass; $current = $current->getParentClass() ?: null) {
            foreach ($current->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $current->getName() || $property->isStatic()) {
                    continue;
                }

                if (! $property->isReadOnly()) {
                    $state[] = $current->getName() . '::$' . $property->getName();

                    continue;
                }

                $type = $property->getType();

                if (! $type instanceof ReflectionNamedType || $type->isBuiltin() || ! class_exists($type->getName())) {
                    continue;
                }

                foreach ($this->mutableStateFor(new ReflectionClass($type->getName()), $visited) as $nested) {
                    $state[] = $current->getName() . '::$' . $property->getName() . '->' . $nested;
                }
            }

            foreach ($current->getTraits() as $trait) {
                $state = [...$state, ...$this->mutableStateFor($trait, $visited)];
            }
        }

        return $state;
    }
}
