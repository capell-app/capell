<?php

declare(strict_types=1);

namespace Capell\Core\Support\Patching;

use PhpToken;

/** @internal Resolves only the facade and helper identities needed by the route editor. */
final class RouteSourceTokens
{
    /** @var list<PhpToken> */
    public readonly array $tokens;

    public function __construct(string $content)
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($content, TOKEN_PARSE), static fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG])));
        $declarations = $this->declarations($tokens);
        $namespace = '';
        $namespaceDepth = 0;
        $depth = 0;
        $classes = [];
        $functions = [];
        foreach ($tokens as $index => $token) {
            if ($token->id === T_NAMESPACE) {
                $namespace = '';
                for ($next = $index + 1; isset($tokens[$next]) && ! $tokens[$next]->is([ord(';'), ord('{')]); $next++) {
                    $namespace .= $tokens[$next]->text;
                }

                $namespaceDepth = $depth + (($tokens[$next]->id ?? null) === ord('{') ? 1 : 0);
                $classes = [];
                $functions = [];
            }

            if ($token->id === T_USE && $depth === $namespaceDepth && ($tokens[$index + 1]->id ?? null) !== ord('(')) {
                $import = '';
                for ($next = $index + 1; isset($tokens[$next]) && $tokens[$next]->id !== ord(';'); $next++) {
                    $import .= $tokens[$next]->text . ' ';
                }

                $this->addImports($import, $classes, $functions);
            }

            if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])) {
                $following = $tokens[$index + 1]->id ?? null;
                $previous = $tokens[$index - 1]->id ?? null;
                if ($following === T_DOUBLE_COLON) {
                    $name = $this->resolveName($token->text, $namespace, $classes);
                    $token->text = isset($declarations['class:' . strtolower($name)]) ? '@' . $name : (in_array(strtolower($name), ['route', 'illuminate\\support\\facades\\route'], true) ? 'Route' : $name);
                } elseif ($following === ord('(') && ! in_array($previous, [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_FUNCTION, T_NEW], true)) {
                    $name = $this->resolveName($token->text, $namespace, $functions);
                    $token->text = isset($declarations['function:' . strtolower($name)]) ? '@' . $name : (strtolower($name) === 'view' ? 'view' : $name);
                }
            }

            if ($token->is([ord('{'), T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->id === ord('}')) {
                $depth--;
                if ($depth < $namespaceDepth) {
                    $namespace = '';
                    $namespaceDepth = 0;
                    $classes = [];
                    $functions = [];
                }
            }
        }

        $this->tokens = $tokens;
    }

    /**
     * Declarations can precede their execution, so collect them before resolving calls.
     *
     * @param  list<PhpToken>  $tokens
     * @return array<string, true>
     */
    private function declarations(array $tokens): array
    {
        $declarations = [];
        $namespace = '';
        $namespaceDepth = 0;
        $depth = 0;
        $classHeader = false;
        $classDepths = [];
        foreach ($tokens as $index => $token) {
            if ($token->id === T_NAMESPACE) {
                $namespace = '';
                for ($next = $index + 1; isset($tokens[$next]) && ! $tokens[$next]->is([ord(';'), ord('{')]); $next++) {
                    $namespace .= $tokens[$next]->text;
                }

                $namespaceDepth = $depth + (($tokens[$next]->id ?? null) === ord('{') ? 1 : 0);
            }

            if ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) && ($tokens[$index - 1]->id ?? null) !== T_DOUBLE_COLON) {
                $classHeader = true;
                if (($tokens[$index + 1]->id ?? null) === T_STRING) {
                    $declarations['class:' . strtolower(ltrim($namespace . '\\' . $tokens[$index + 1]->text, '\\'))] = true;
                }
            }

            if ($token->id === T_FUNCTION && ! in_array($depth, $classDepths, true)) {
                $next = $index + 1;
                if (($tokens[$next]->text ?? null) === '&') {
                    $next++;
                }

                if (($tokens[$next]->id ?? null) === T_STRING && ($tokens[$next + 1]->id ?? null) === ord('(')) {
                    $declarations['function:' . strtolower(ltrim($namespace . '\\' . $tokens[$next]->text, '\\'))] = true;
                }
            }

            if ($token->is([ord('{'), T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
                if ($classHeader) {
                    $classDepths[] = $depth;
                    $classHeader = false;
                }
            } elseif ($token->id === ord('}')) {
                if (in_array($depth, $classDepths, true)) {
                    array_pop($classDepths);
                }

                $depth--;
                if ($depth < $namespaceDepth) {
                    $namespace = '';
                    $namespaceDepth = 0;
                }
            }
        }

        return $declarations;
    }

    /** @param array<string, string> $imports */
    private function resolveName(string $name, string $namespace, array $imports): string
    {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }

        if (str_starts_with(strtolower($name), 'namespace\\')) {
            return ltrim($namespace . '\\' . substr($name, 10), '\\');
        }

        $parts = explode('\\', $name, 2);
        if (isset($imports[strtolower($parts[0])])) {
            return $imports[strtolower($parts[0])] . (isset($parts[1]) ? '\\' . $parts[1] : '');
        }

        return ltrim($namespace . '\\' . $name, '\\');
    }

    /**
     * @param  array<string, string>  $classes
     * @param  array<string, string>  $functions
     */
    private function addImports(string $source, array &$classes, array &$functions): void
    {
        $type = 'class';
        $source = trim($source);
        if (preg_match('/\A(function|const)\s+/i', $source, $match) === 1) {
            $type = strtolower($match[1]);
            $source = substr($source, strlen($match[0]));
        }

        $prefix = '';
        if (str_contains($source, '{')) {
            [$prefix, $source] = explode('{', $source, 2);
            $prefix = str_replace(' ', '', $prefix);
            $source = rtrim(trim($source), '} ');
        }

        foreach (explode(',', $source) as $item) {
            $itemType = $type;
            $item = trim($item);
            if (preg_match('/\A(function|const)\s+/i', $item, $match) === 1) {
                $itemType = strtolower($match[1]);
                $item = substr($item, strlen($match[0]));
            }

            $parts = preg_split('/\s+as\s+/i', $item) ?: [];
            $name = ltrim($prefix . str_replace(' ', '', $parts[0] ?? ''), '\\');
            $segments = explode('\\', $name);
            $alias = strtolower(trim($parts[1] ?? $segments[array_key_last($segments)]));
            if ($itemType === 'function') {
                $functions[$alias] = $name;
            } elseif ($itemType === 'class') {
                $classes[$alias] = $name;
            }
        }
    }
}
