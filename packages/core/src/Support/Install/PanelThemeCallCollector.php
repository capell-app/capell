<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Override;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

final class PanelThemeCallCollector extends NodeVisitorAbstract
{
    /** @var array<int, MethodCall> */
    public array $calls = [];

    /** @param list<string> $themeMethods */
    public function __construct(
        private readonly ?string $argumentName,
        private readonly array $themeMethods,
    ) {}

    #[Override]
    public function enterNode(Node $node): ?int
    {
        // Deferred callbacks and nested declarations do not configure this panel.
        if ($node instanceof FunctionLike || $node instanceof ClassLike) {
            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        $call = $node instanceof Return_ ? $node->expr : $node;
        $calls = [];

        while ($call instanceof MethodCall) {
            if ($call->name instanceof Identifier && in_array($call->name->toString(), $this->themeMethods, true)) {
                $calls[] = $call;
            }

            $call = $call->var;
        }

        if ($node instanceof Return_ || ($call instanceof Variable && $this->argumentName !== null && $call->name === $this->argumentName)) {
            foreach ($calls as $themeCall) {
                $this->calls[spl_object_id($themeCall)] = $themeCall;
            }
        }

        return null;
    }
}
