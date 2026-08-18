<?php

declare(strict_types=1);

namespace Capell\Installer\Support\InstallGuide\Patches;

use Capell\Core\Support\Patching\PatchStatus;
use Capell\Core\Support\Patching\PhpFileEditor;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use RuntimeException;
use Throwable;

/**
 * Shared AST chain-walking collaborator for the AdminPanelProvider patches.
 * Rewrites the customer's own `panel()` method chain during install, so its
 * probe/apply logic is deliberately its own tested surface rather than a
 * trait exercised only indirectly through six concrete Patch classes.
 */
final class AdminPanelProviderPatcher
{
    public function __construct(
        private readonly string $providerPath = 'app/Providers/Filament/AdminPanelProvider.php',
        private readonly string $className = 'AdminPanelProvider',
        private readonly string $panelMethodName = 'panel',
    ) {}

    public function probe(callable $decide): PatchStatus
    {
        $absolutePath = base_path($this->providerPath);

        if (! file_exists($absolutePath)) {
            return PatchStatus::Unsupported;
        }

        try {
            $panelMethodNode = $this->panelMethodNode(new PhpFileEditor($absolutePath));

            if (! $panelMethodNode instanceof ClassMethod) {
                return PatchStatus::Unsupported;
            }

            if ($panelMethodNode->stmts === null || count($panelMethodNode->stmts) !== 1) {
                return PatchStatus::Customised;
            }

            $stmt = $panelMethodNode->stmts[0];
            if (! $this->isStockMethodChain($stmt)) {
                return PatchStatus::Customised;
            }

            $status = $decide($stmt);

            return $status instanceof PatchStatus ? $status : PatchStatus::Unsupported;
        } catch (RuntimeException|Throwable) {
            return PatchStatus::Unsupported;
        }
    }

    /**
     * @param  array<int, class-string>  $useStatements
     * @param  array<int, class-string>  $removeUseStatements
     */
    public function apply(callable $decide, callable $mutate, array $useStatements = [], array $removeUseStatements = []): void
    {
        $absolutePath = base_path($this->providerPath);

        throw_unless(
            file_exists($absolutePath),
            RuntimeException::class,
            'AdminPanelProvider not found at: ' . $absolutePath,
        );

        $status = $this->probe($decide);
        if ($status !== PatchStatus::Applicable) {
            throw new RuntimeException('Cannot apply patch when status is: ' . $status->value);
        }

        $editor = new PhpFileEditor($absolutePath);
        $editor->backup();

        if ($useStatements !== []) {
            $editor->addUseStatements($useStatements);
        }

        if ($removeUseStatements !== []) {
            $editor->removeUseStatements($removeUseStatements);
        }

        $panelMethodNode = $this->panelMethodNode($editor);
        throw_unless($panelMethodNode instanceof ClassMethod, RuntimeException::class, 'Could not find panel() method');

        if ($panelMethodNode->stmts !== null && $panelMethodNode->stmts !== []) {
            $mutate($panelMethodNode->stmts[0]);
        }

        $editor->save();
    }

    public function hasMethodCall(Node $stmt, string $methodName): bool
    {
        return $this->findMethodCall($stmt, $methodName) instanceof MethodCall;
    }

    public function findMethodCall(Node $stmt, string $methodName): ?MethodCall
    {
        if (! property_exists($stmt, 'expr') || ! $stmt->expr instanceof MethodCall) {
            return null;
        }

        $methodCall = $stmt->expr;
        while ($methodCall instanceof MethodCall) {
            if ($this->methodName($methodCall) === $methodName) {
                return $methodCall;
            }

            $methodCall = $methodCall->var;
        }

        return null;
    }

    /**
     * @param  array<int, Arg>  $args
     */
    public function appendMethodCall(Node $stmt, string $methodName, array $args = []): void
    {
        if (! property_exists($stmt, 'expr') || ! $stmt->expr instanceof MethodCall) {
            return;
        }

        $stmt->expr = new MethodCall($stmt->expr, $methodName, $args);
    }

    public function insertMethodCallAfter(Node $stmt, string $afterMethodName, callable $createMethodCall): void
    {
        if (! property_exists($stmt, 'expr') || ! $stmt->expr instanceof MethodCall) {
            return;
        }

        $chainStack = $this->methodCallChain($stmt->expr);
        $insertIndex = $this->methodCallIndex($chainStack, $afterMethodName);
        $insertIndex = $insertIndex !== null ? $insertIndex + 1 : count($chainStack);

        $currentVar = null;
        $counter = count($chainStack);

        for ($index = 0; $index < $counter; $index++) {
            $call = $chainStack[$index];

            if ($index === 0) {
                $currentVar = $call->var;
            }

            $call->var = $currentVar;

            if ($index === $insertIndex - 1) {
                $newMethodCall = $createMethodCall($call);
                $currentVar = $newMethodCall instanceof MethodCall ? $newMethodCall : $call;

                continue;
            }

            $currentVar = $call;
        }

        $stmt->expr = $currentVar;
    }

    private function panelMethodNode(PhpFileEditor $editor): ?ClassMethod
    {
        $classNode = $editor->findClass($this->className);

        if (! $classNode instanceof Class_) {
            return null;
        }

        $panelMethodNode = $editor->findMethodInClass($this->className, $this->panelMethodName);

        return $panelMethodNode instanceof ClassMethod ? $panelMethodNode : null;
    }

    private function isStockMethodChain(Node $stmt): bool
    {
        if (! property_exists($stmt, 'expr') || ! $stmt->expr instanceof MethodCall) {
            return false;
        }

        $methodCall = $stmt->expr;
        while ($methodCall instanceof MethodCall) {
            $methodCall = $methodCall->var;
        }

        return $methodCall instanceof Node;
    }

    private function methodName(MethodCall $methodCall): ?string
    {
        if ($methodCall->name instanceof Node && property_exists($methodCall->name, 'name')) {
            return $methodCall->name->name;
        }

        return is_string($methodCall->name) ? $methodCall->name : null;
    }

    /**
     * @return array<int, MethodCall>
     */
    private function methodCallChain(MethodCall $methodCall): array
    {
        $chainStack = [];
        $currentCall = $methodCall;

        while ($currentCall instanceof MethodCall) {
            $chainStack[] = $currentCall;
            $currentCall = $currentCall->var;
        }

        return array_reverse($chainStack);
    }

    /**
     * @param  array<int, MethodCall>  $chainStack
     */
    private function methodCallIndex(array $chainStack, string $methodName): ?int
    {
        foreach ($chainStack as $index => $call) {
            if ($this->methodName($call) === $methodName) {
                return $index;
            }
        }

        return null;
    }
}
