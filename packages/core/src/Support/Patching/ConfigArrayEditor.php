<?php

declare(strict_types=1);

namespace Capell\Core\Support\Patching;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use RuntimeException;

class ConfigArrayEditor
{
    private readonly NodeFinder $nodeFinder;

    public function __construct(private readonly PhpFileEditor $editor)
    {
        $this->nodeFinder = new NodeFinder;
    }

    /**
     * Check if a key exists at the specified path.
     *
     * @param  string  $arrayPath  Dot-separated path (e.g., 'disks.local')
     */
    public function hasKey(string $arrayPath): bool
    {
        [$rootKey, $subKey] = array_pad(explode('.', $arrayPath, 2), 2, null);

        if ($rootKey === null) {
            return false;
        }

        $ast = $this->editor->getAst();
        /** @var Return_|null */
        $returnStatement = $this->nodeFinder->findFirst(
            $ast,
            static fn (Node $node): bool => $node instanceof Return_,
        );

        if ($returnStatement === null || ! $returnStatement->expr instanceof Array_) {
            return false;
        }

        $array = $returnStatement->expr;

        foreach ($array->items as $item) {
            if ($item === null) {
                continue;
            }

            if ($item->key instanceof String_ && $item->key->value === $rootKey) {
                if ($subKey === null) {
                    return true;
                }

                if ($item->value instanceof Array_) {
                    foreach ($item->value->items as $subItem) {
                        if ($subItem === null) {
                            continue;
                        }

                        if ($subItem->key instanceof String_ && $subItem->key->value === $subKey) {
                            return true;
                        }
                    }
                }

                return false;
            }
        }

        return false;
    }

    /**
     * Insert a key-value pair into the config array.
     *
     * @param  string  $arrayPath  Dot-separated path (e.g., 'disks.page_cache')
     * @param  Node  $valueNode  The AST expression node to insert as the value
     */
    public function insertKey(string $arrayPath, Node $valueNode): self
    {
        throw_unless($valueNode instanceof Expr, RuntimeException::class, 'Config array values must be expression nodes.');

        [$rootKey, $subKey] = array_pad(explode('.', $arrayPath, 2), 2, null);

        throw_if($rootKey === null || $subKey === null, RuntimeException::class, 'Invalid array path: ' . $arrayPath);

        $ast = $this->editor->getAst();
        /** @var Return_|null */
        $returnStatement = $this->nodeFinder->findFirst(
            $ast,
            static fn (Node $node): bool => $node instanceof Return_,
        );

        throw_if($returnStatement === null || ! $returnStatement->expr instanceof Array_, RuntimeException::class, 'Cannot find return statement with array in config file');

        $array = $returnStatement->expr;

        foreach ($array->items as $item) {
            if ($item === null) {
                continue;
            }

            if ($item->key instanceof String_ && $item->key->value === $rootKey) {
                throw_unless($item->value instanceof Array_, RuntimeException::class, sprintf("Root key '%s' does not contain an array", $rootKey));

                $newItem = new ArrayItem(
                    $valueNode,
                    new String_($subKey),
                    false,
                    [],
                );

                array_unshift($item->value->items, $newItem);

                return $this;
            }
        }

        throw new RuntimeException(sprintf("Root key '%s' not found in config array", $rootKey));
    }
}
