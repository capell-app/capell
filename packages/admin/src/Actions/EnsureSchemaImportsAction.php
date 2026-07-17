<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use ReflectionClass;

/**
 * @method static string run(string $content, ReflectionClass<*> $reflector, string $originalNamespace)
 */
class EnsureSchemaImportsAction
{
    use AsFake;
    use AsObject;

    /** @param ReflectionClass<*> $reflector */
    public function handle(string $content, ReflectionClass $reflector, string $originalNamespace): string
    {
        $lines = preg_split('/(\r\n|\n|\r)/', $content);
        if ($lines === false || $lines === []) {
            $lines = [$content];
        }

        $existingImports = $this->parseExistingImports(implode(PHP_EOL, $lines));
        $existingBasenames = array_map(static fn (array $import): string => $import['basename'], $existingImports);
        $existingImportLines = $this->collectExistingImportLines($lines);

        $candidates = $this->gatherImportCandidates($reflector);
        $originalNamespacePrefix = rtrim($originalNamespace, '\\');
        $className = $reflector->getName();
        $importsToAdd = $this->buildImportsToAdd(
            $candidates,
            $className,
            $originalNamespacePrefix,
            $existingBasenames,
            $existingImportLines,
        );

        if ($importsToAdd !== []) {
            $this->insertImports($lines, $importsToAdd);
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<string, true>
     */
    private function collectExistingImportLines(array $lines): array
    {
        $existingImportLines = [];
        foreach ($lines as $line) {
            $trimmed = trim((string) $line);
            if (str_starts_with($trimmed, 'use ')) {
                $existingImportLines[$trimmed] = true;
            }
        }

        return $existingImportLines;
    }

    /**
     * @param  ReflectionClass<*>  $reflector
     * @return array<int, class-string>
     */
    private function gatherImportCandidates(ReflectionClass $reflector): array
    {
        $candidates = [];
        if ($parent = $reflector->getParentClass()) {
            $candidates[] = $parent->getName();
        }

        foreach ($reflector->getInterfaceNames() as $interface) {
            $candidates[] = $interface;
        }

        foreach ($reflector->getTraitNames() as $trait) {
            $candidates[] = $trait;
        }

        return array_unique($candidates);
    }

    /**
     * @param  array<int, class-string>  $candidates
     * @param  array<int, string>  $existingBasenames
     * @param  array<string, true>  $existingImportLines
     * @return array<int, string>
     */
    private function buildImportsToAdd(
        array $candidates,
        string $className,
        string $originalNamespacePrefix,
        array &$existingBasenames,
        array &$existingImportLines,
    ): array {
        $importsToAdd = [];
        foreach ($candidates as $fqcn) {
            if ($fqcn === $className) {
                continue;
            }

            if (! str_starts_with((string) $fqcn, $originalNamespacePrefix)) {
                continue;
            }

            $short = $this->basename($fqcn);
            $alias = null;
            if (in_array($short, $existingBasenames, true)) {
                $alias = $this->generateAlias($fqcn, $existingBasenames);
            }

            $importLine = 'use ' . $fqcn . ($alias !== null ? ' as ' . $alias : '') . ';';
            if (isset($existingImportLines[trim($importLine)])) {
                continue;
            }

            $importsToAdd[] = $importLine;
            $existingBasenames[] = $alias ?? $short;
            $existingImportLines[trim($importLine)] = true;
        }

        return $importsToAdd;
    }

    /**
     * @param  array<int, string>  $lines
     * @param  array<int, string>  $importsToAdd
     */
    private function insertImports(array &$lines, array $importsToAdd): void
    {
        $namespaceLineIndex = array_find_key($lines, fn (string $line): bool => str_starts_with(trim($line), 'namespace '));
        if ($namespaceLineIndex !== null) {
            $insertAt = $namespaceLineIndex + 1;
            $counter = count($lines);
            for ($lineIndex = $insertAt; $lineIndex < $counter; $lineIndex++) {
                if (str_starts_with(trim((string) $lines[$lineIndex]), 'use ')) {
                    $insertAt = $lineIndex + 1;
                } elseif (trim((string) $lines[$lineIndex]) !== '') {
                    break;
                }
            }

            foreach ($importsToAdd as $importLine) {
                array_splice($lines, $insertAt, 0, [$importLine]);
                $insertAt++;
            }
        }
    }

    /** @return array<int, array{fqcn: string, alias: string|null, basename: string}> */
    private function parseExistingImports(string $content): array
    {
        preg_match_all('/^use\s+([A-Za-z_][A-Za-z0-9_\\\\]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)(?:\s+as\s+(\w+))?;/m', $content, $matches, PREG_SET_ORDER);
        $imports = [];
        foreach ($matches as $match) {
            $fqcn = $match[1];
            $alias = $match[2] ?? null;
            $imports[] = [
                'fqcn' => $fqcn,
                'alias' => $alias,
                'basename' => $alias ?? $this->basename($fqcn),
            ];
        }

        return $imports;
    }

    private function basename(string $fqcn): string
    {
        $parts = explode('\\', trim($fqcn, '\\'));
        $last = end($parts);
        if ($last === '') {
            return $fqcn;
        }

        return $last;
    }

    /** @param array<int, string> $reserved */
    private function generateAlias(string $fqcn, array $reserved): string
    {
        $parts = explode('\\', trim($fqcn, '\\'));
        $class = array_pop($parts) ?: 'Class';
        $previousPart = end($parts);
        $previous = ($previousPart === false || $previousPart === '') ? 'Base' : $previousPart;
        $baseAlias = $previous . $class;
        $alias = $baseAlias;
        $suffix = 2;
        while (in_array($alias, $reserved, true)) {
            $alias = $baseAlias . $suffix;
            $suffix++;
        }

        return $alias;
    }
}
