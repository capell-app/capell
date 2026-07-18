<?php

declare(strict_types=1);

namespace Capell\Core\Support\Patching;

use Illuminate\Support\Facades\Date;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\UseUse;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PhpParser\Token;
use RuntimeException;
use Throwable;

final class PhpFileEditor
{
    private Parser $parser;

    private readonly Standard $printer;

    private readonly NodeFinder $nodeFinder;

    private readonly string $filePath;

    private readonly string $originalContent;

    /** @var Node[] */
    private readonly array $originalAst;

    /** @var array<int, Token> */
    private readonly array $originalTokens;

    /** @var Node[] */
    private array $ast;

    public function __construct(string $filePath)
    {
        throw_unless(file_exists($filePath), RuntimeException::class, 'File does not exist at path: ' . $filePath);

        $this->filePath = $filePath;
        $fileContents = file_get_contents($filePath);
        $this->originalContent = $fileContents !== false ? $fileContents : '';

        try {
            $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
            $this->originalAst = $this->parser->parse($this->originalContent) ?? [];
            $this->originalTokens = $this->parser->getTokens();
            $traverser = new NodeTraverser;
            $traverser->addVisitor(new CloningVisitor);
            $this->ast = $traverser->traverse($this->originalAst);
        } catch (Throwable $throwable) {
            throw new RuntimeException('Failed to parse PHP file: ' . $throwable->getMessage(), $throwable->getCode(), $throwable);
        }

        $this->printer = new Standard;
        $this->nodeFinder = new NodeFinder;
    }

    /**
     * @param  array<string>  $uses
     */
    public function addUseStatements(array $uses): self
    {
        $existingUses = $this->nodeFinder->findInstanceOf($this->ast, Use_::class);
        $existingNames = [];

        foreach ($existingUses as $useStatement) {
            foreach ($useStatement->uses as $useUse) {
                // Prefer the explicit alias; fall back to the imported name's
                // last segment (mirrors UseItem::getAlias()).
                $existingNames[] = $useUse->alias->name ?? $useUse->name->getLast();
            }
        }

        foreach ($uses as $useStatement) {
            $parts = explode('\\', trim($useStatement, '\\'));
            $lastPart = end($parts);

            if (! in_array($lastPart, $existingNames, true)) {
                $useNode = new Use_([
                    new UseUse(
                        new Name(trim($useStatement, '\\')),
                    ),
                ]);

                $this->insertUseStatement($useNode);
                $existingNames[] = $lastPart;
            }
        }

        return $this;
    }

    /**
     * @param  array<string>  $uses
     */
    public function removeUseStatements(array $uses): self
    {
        $uses = array_map(static fn (string $use): string => trim($use, '\\'), $uses);

        foreach ($this->ast as $node) {
            if (! $node instanceof Namespace_) {
                continue;
            }

            $node->stmts = $this->removeUseStatementsFromNodes($node->stmts, $uses);

            return $this;
        }

        $this->ast = $this->removeUseStatementsFromNodes($this->ast, $uses);

        return $this;
    }

    public function findClass(?string $className = null): ?Class_
    {
        /** @var Class_|null */
        return $this->nodeFinder->findFirst(
            $this->ast,
            static fn (Node $node): bool => $node instanceof Class_
                && ($className === null || $node->name?->name === class_basename($className)),
        );
    }

    public function findMethodInClass(?string $className, string $methodName): ?ClassMethod
    {
        $classNode = $this->findClass($className);

        if (! $classNode instanceof Class_) {
            return null;
        }

        /** @var ClassMethod|null */
        return $this->nodeFinder->findFirst($classNode, static fn (Node $node): bool => $node instanceof ClassMethod && $node->name->name === $methodName);
    }

    public function findNamespace(): ?string
    {
        /** @var Namespace_|null $namespace */
        $namespace = $this->nodeFinder->findFirstInstanceOf($this->ast, Namespace_::class);

        return $namespace?->name?->toString();
    }

    public function originalContent(): string
    {
        return $this->originalContent;
    }

    public function print(): string
    {
        return $this->printer->printFormatPreserving($this->ast, $this->originalAst, $this->originalTokens);
    }

    public function save(): void
    {
        $content = $this->print();
        $bytesWritten = @file_put_contents($this->filePath, $content);

        throw_if(
            $bytesWritten === false || $bytesWritten !== strlen($content),
            RuntimeException::class,
            'Failed to write PHP file at path: ' . $this->filePath,
        );
    }

    public function backup(): string
    {
        $backupBaseDir = sys_get_temp_dir() . '/capell/php-file-backups';
        try {
            if (function_exists('storage_path')) {
                $backupBaseDir = storage_path('capell/php-file-backups');
            }
        } catch (Throwable) {
            // Fall back to temp directory if storage_path fails
        }

        $backupDir = $backupBaseDir . '/' . Date::now()->format('Y-m-d-His');
        if (! is_dir($backupDir)) {
            $created = @mkdir($backupDir, 0755, true);

            throw_unless(
                $created || is_dir($backupDir),
                RuntimeException::class,
                'Failed to create PHP backup directory: ' . $backupDir,
            );
        }

        $backupPath = $backupDir . '/' . basename($this->filePath);
        $copied = @copy($this->filePath, $backupPath);

        throw_unless(
            $copied,
            RuntimeException::class,
            'Failed to back up PHP file to path: ' . $backupPath,
        );

        return $backupPath;
    }

    /**
     * @return Node[]
     */
    public function getAst(): array
    {
        return $this->ast;
    }

    /**
     * @param  Node[]  $ast
     */
    public function setAst(array $ast): self
    {
        $this->ast = $ast;

        return $this;
    }

    private function insertUseStatement(Use_ $useNode): void
    {
        $useName = $useNode->uses[0]->name->toString();

        foreach ($this->ast as $node) {
            if (! $node instanceof Namespace_) {
                continue;
            }

            $insertIndex = 0;
            while (isset($node->stmts[$insertIndex]) && $node->stmts[$insertIndex] instanceof Use_) {
                $existingUseName = $node->stmts[$insertIndex]->uses[0]->name->toString();

                if (strcmp($useName, $existingUseName) < 0) {
                    break;
                }

                $insertIndex++;
            }

            array_splice($node->stmts, $insertIndex, 0, [$useNode]);

            return;
        }

        $insertIndex = 0;
        while (
            isset($this->ast[$insertIndex])
            && $this->ast[$insertIndex] instanceof Declare_
        ) {
            $insertIndex++;
        }

        while (isset($this->ast[$insertIndex]) && $this->ast[$insertIndex] instanceof Use_) {
            $existingUseName = $this->ast[$insertIndex]->uses[0]->name->toString();

            if (strcmp($useName, $existingUseName) < 0) {
                break;
            }

            $insertIndex++;
        }

        array_splice($this->ast, $insertIndex, 0, [$useNode]);
    }

    /**
     * @template TNode of Node
     *
     * @param  array<int, TNode>  $nodes
     * @param  array<string>  $uses
     * @return list<TNode>
     */
    private function removeUseStatementsFromNodes(array $nodes, array $uses): array
    {
        foreach ($nodes as $index => $node) {
            if (! $node instanceof Use_) {
                continue;
            }

            $node->uses = array_values(array_filter(
                $node->uses,
                static fn (UseUse $useUse): bool => ! in_array($useUse->name->toString(), $uses, true),
            ));

            if ($node->uses === []) {
                unset($nodes[$index]);
            }
        }

        return array_values($nodes);
    }
}
