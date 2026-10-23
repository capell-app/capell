<?php

declare(strict_types=1);

namespace Capell\Core\Support\Patching;

use PhpToken;

/** @internal Detects possible application roots without executing application code. */
final class RootRouteDetector
{
    /** @var array<int, int> */
    private array $ends = [];

    /** @param list<PhpToken> $tokens */
    public function __construct(private readonly array $tokens)
    {
        $stack = [];
        foreach ($tokens as $index => $token) {
            if ($token->is([ord('('), ord('['), ord('{'), T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE])) {
                $stack[] = $index;
            } elseif ($token->is([ord(')'), ord(']'), ord('}')])) {
                $start = array_pop($stack);
                if ($start !== null) {
                    $this->ends[$start] = $index;
                }
            }
        }
    }

    public function hasRootRoute(): bool
    {
        return $this->scan(0, count($this->tokens), false);
    }

    /** @param list<string> $routers */
    private function scan(int $start, int $end, bool $prefixed, array $routers = []): bool
    {
        for ($index = $start; $index < $end; $index++) {
            $token = $this->tokens[$index];
            if ($token->id === T_FUNCTION) {
                $body = $index + 1;
                while ($body < $end && ! $this->tokens[$body]->is([ord('{'), ord(';')])) {
                    $body = isset($this->ends[$body]) ? $this->ends[$body] + 1 : $body + 1;
                }

                $close = $this->ends[$body] ?? $body;
                if ($this->isInvoked($close, $end) && $this->scan($body + 1, $close, $prefixed, $routers)) {
                    return true;
                }

                $index = $close;

                continue;
            }

            if ($token->is([T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM]) && ($this->tokens[$index - 1]->id ?? null) !== T_DOUBLE_COLON) {
                while ($index < $end && ! $this->tokens[$index]->is([ord('{'), ord(';')])) {
                    $index = isset($this->ends[$index]) ? $this->ends[$index] + 1 : $index + 1;
                }

                $index = $this->ends[$index] ?? $index;

                continue;
            }

            if ($token->id === T_FN) {
                $arrowEnd = $this->expressionEnd($index, $end);
                $body = $this->arrowBody($index, $arrowEnd);
                if ($this->isInvoked($arrowEnd, $end) && $this->scan($body, $arrowEnd + 1, $prefixed, $routers)) {
                    return true;
                }

                $index = $arrowEnd;

                continue;
            }

            $facade = $token->text === 'Route' && ($this->tokens[$index + 1]->id ?? null) === T_DOUBLE_COLON;
            $router = $token->id === T_VARIABLE && in_array($token->text, $routers, true)
                && ($this->tokens[$index + 1]->id ?? null) === T_OBJECT_OPERATOR;
            if (! $facade && ! $router) {
                continue;
            }

            $cursor = $index + 2;
            $routePrefixed = $prefixed;
            $root = false;
            $registered = false;
            $resourceMethods = null;
            $resourceOptions = [];
            while (($this->tokens[$cursor + 1]->id ?? null) === ord('(')) {
                $method = strtolower($this->tokens[$cursor]->text);
                $close = $this->ends[$cursor + 1];
                $arguments = $this->arguments($cursor + 2, $close);
                if ($method === 'prefix') {
                    $prefix = $this->nonEmptyPrefix($this->argument($arguments, 'prefix', 0));
                    // Registrar attributes replace one another; Route::prefix()
                    // after registration prepends to the already registered URI.
                    if ($registered) {
                        $root = $root && ! $prefix;
                    } else {
                        $routePrefixed = $prefixed || $prefix;
                    }
                } elseif ($method === 'seturi' && $registered) {
                    $root = $this->possibleRoot($this->argument($arguments, 'uri', 0));
                } elseif ($method === 'group') {
                    $attributes = $this->argument($arguments, 'attributes', 0);
                    if ($this->scanGroup($cursor + 2, $close, $routePrefixed || $this->groupHasPrefix($attributes), $routers)) {
                        return true;
                    }
                } elseif (in_array($method, ['get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'match', 'addroute', 'view', 'redirect', 'permanentredirect'], true)) {
                    $uri = $this->argument($arguments, 'uri', in_array($method, ['match', 'addroute'], true) ? 1 : 0);
                    $registered = true;
                    $root = ! $routePrefixed && $this->possibleRoot($uri);
                } elseif (in_array($method, ['resource', 'apiresource', 'singleton', 'apisingleton', 'resources', 'apiresources', 'softdeletableresources', 'singletons', 'apisingletons'], true)) {
                    $plural = str_ends_with($method, 's');
                    $name = $this->argument($arguments, $plural ? (str_contains($method, 'singleton') ? 'singletons' : 'resources') : 'name', 0);
                    $registered = true;
                    $root = ! $routePrefixed && ($plural ? $this->resourceMapHasRoot($name) : $this->possibleRoot($name));
                    $resourceMethods = str_contains($method, 'singleton') ? ['show', 'update'] : ['index', 'store'];
                    $options = $this->argument($arguments, 'options', $plural ? 1 : 2);
                    $resourceOptions = $this->resourceOptions($options);
                    if (str_contains($method, 'singleton')) {
                        if ($this->arrayOption($options, 'creatable') !== null) {
                            $resourceMethods = array_merge($resourceMethods, ['store', 'destroy']);
                        } elseif ($this->arrayOption($options, 'destroyable') !== null) {
                            $resourceMethods[] = 'destroy';
                        }
                    }
                } elseif ($resourceMethods !== null && in_array($method, ['creatable', 'destroyable'], true)) {
                    $resourceMethods = array_merge($resourceMethods, $method === 'creatable' ? ['store', 'destroy'] : ['destroy']);
                } elseif ($resourceMethods !== null && in_array($method, ['only', 'except'], true)) {
                    // These setters replace their respective options. Retain
                    // both until the end, just as PendingResourceRegistration does.
                    $resourceOptions[$method] = $this->stringValues(count($arguments) === 1 ? $this->argument($arguments, 'methods', 0) : array_merge(...$arguments));
                }

                $index = $close;
                if (($this->tokens[$close + 1]->id ?? null) !== T_OBJECT_OPERATOR) {
                    break;
                }

                $cursor = $close + 2;
            }

            if ($resourceMethods !== null) {
                if (isset($resourceOptions['only'])) {
                    $resourceMethods = array_intersect($resourceMethods, $resourceOptions['only']);
                }

                if (isset($resourceOptions['except'])) {
                    $resourceMethods = array_diff($resourceMethods, $resourceOptions['except']);
                }

                $root = $root && $resourceMethods !== [];
            }

            if ($root) {
                return true;
            }
        }

        return false;
    }

    private function isInvoked(int $close, int $end): bool
    {
        $next = $close + 1;
        while ($next < $end && $this->tokens[$next]->id === ord(')')) {
            $next++;
        }

        return ($this->tokens[$next]->id ?? null) === ord('(');
    }

    private function arrowBody(int $start, int $end): int
    {
        for ($index = $start + 1; $index < $end; $index++) {
            if (isset($this->ends[$index])) {
                $index = $this->ends[$index];
            } elseif ($this->tokens[$index]->id === T_DOUBLE_ARROW) {
                return $index + 1;
            }
        }

        return $end;
    }

    /** @param list<string> $routers */
    private function scanGroup(int $start, int $end, bool $prefixed, array $routers): bool
    {
        for ($index = $start; $index < $end; $index++) {
            if (! $this->tokens[$index]->is([T_FUNCTION, T_FN])) {
                continue;
            }

            $parameters = $index + 1;
            while ($parameters < $end && $this->tokens[$parameters]->id !== ord('(')) {
                $parameters++;
            }

            $parametersEnd = $this->ends[$parameters];
            $groupRouters = $routers;
            for ($parameter = $parameters + 1; $parameter < $parametersEnd; $parameter++) {
                if ($this->tokens[$parameter]->id === T_VARIABLE) {
                    $groupRouters[] = $this->tokens[$parameter]->text;
                    break;
                }
            }

            if ($this->tokens[$index]->id === T_FN) {
                $close = $this->expressionEnd($index, $end);
                if ($this->scan($this->arrowBody($index, $close), $close + 1, $prefixed, $groupRouters)) {
                    return true;
                }
            } else {
                $body = $parametersEnd + 1;
                while ($body < $end && $this->tokens[$body]->id !== ord('{')) {
                    $body = isset($this->ends[$body]) ? $this->ends[$body] + 1 : $body + 1;
                }

                $close = $this->ends[$body];
                if ($this->scan($body + 1, $close, $prefixed, $groupRouters)) {
                    return true;
                }
            }

            $index = $close;
        }

        return false;
    }

    /** @param list<PhpToken> $uri */
    private function possibleRoot(array $uri): bool
    {
        $literal = count($uri) === 1 ? $this->literal($uri[0]) : null;

        // An unresolved URI may be a root. Removal still requires a literal.
        return $uri !== [] && ($literal === null || trim($literal, '/') === '');
    }

    /** @param list<PhpToken> $tokens */
    private function resourceMapHasRoot(array $tokens): bool
    {
        if (($tokens[0]->id ?? null) !== ord('[')) {
            return $tokens !== [];
        }

        return array_any($tokens, fn (PhpToken $token, int $index): bool => ($tokens[$index + 1]->id ?? null) === T_DOUBLE_ARROW && $this->possibleRoot([$token]));
    }

    /**
     * @param  list<PhpToken>  $options
     * @return array<string, list<string>|null>
     */
    private function resourceOptions(array $options): array
    {
        $filters = [];
        foreach (['only', 'except'] as $key) {
            $value = $this->arrayOption($options, $key);
            if ($value !== null) {
                $filters[$key] = $this->stringValues($value);
            }
        }

        return $filters;
    }

    /**
     * Only read outer option keys; nested name/middleware maps are unrelated.
     * PHP keeps the final occurrence of a duplicate option.
     *
     * @param  list<PhpToken>  $tokens
     * @return list<PhpToken>|null
     */
    private function arrayOption(array $tokens, string $key): ?array
    {
        $depth = 0;
        $result = null;
        foreach ($tokens as $index => $token) {
            if ($token->is([ord('['), ord('('), ord('{'), T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->is([ord(']'), ord(')'), ord('}')])) {
                $depth--;
            }

            if ($depth !== 1) {
                continue;
            }

            if ($this->literal($token) !== $key) {
                continue;
            }

            if (($tokens[$index + 1]->id ?? null) !== T_DOUBLE_ARROW) {
                continue;
            }

            $result = [];
            $valueDepth = 0;
            for ($next = $index + 2; isset($tokens[$next]); $next++) {
                $value = $tokens[$next];
                if ($valueDepth === 0 && $value->is([ord(','), ord(']'), ord(')')])) {
                    break;
                }

                if ($value->is([ord('['), ord('('), ord('{'), T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                    $valueDepth++;
                } elseif ($value->is([ord(']'), ord(')'), ord('}')])) {
                    $valueDepth--;
                }

                $result[] = $value;
            }
        }

        return $result;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return list<string>|null
     */
    private function stringValues(array $tokens): ?array
    {
        $values = [];
        foreach ($tokens as $token) {
            $literal = $this->literal($token);
            if ($literal !== null) {
                $values[] = $literal;
            } elseif (! $token->is([ord('['), ord(']'), ord(',')])) {
                // Dynamic filters cannot prove that the root actions are absent.
                return null;
            }
        }

        return $values;
    }

    private function expressionEnd(int $start, int $end): int
    {
        for ($index = $start + 1; $index < $end; $index++) {
            if (isset($this->ends[$index])) {
                $index = $this->ends[$index];
            } elseif ($this->tokens[$index]->is([ord(';'), ord(','), ord(')'), ord(']'), ord('}')])) {
                return $index - 1;
            }
        }

        return $end - 1;
    }

    /** @return list<list<PhpToken>> */
    private function arguments(int $start, int $end): array
    {
        $arguments = [];
        $offset = $start;
        for ($index = $start; $index < $end; $index++) {
            if (isset($this->ends[$index])) {
                $index = $this->ends[$index];
            } elseif ($this->tokens[$index]->id === ord(',')) {
                $arguments[] = array_slice($this->tokens, $offset, $index - $offset);
                $offset = $index + 1;
            }
        }

        $arguments[] = array_slice($this->tokens, $offset, $end - $offset);

        return $arguments;
    }

    /**
     * @param  list<list<PhpToken>>  $arguments
     * @return list<PhpToken>
     */
    private function argument(array $arguments, string $name, int $position): array
    {
        foreach ($arguments as $argument) {
            if (($argument[0]->text ?? null) === $name && ($argument[1]->id ?? null) === ord(':')) {
                return array_slice($argument, 2);
            }
        }

        $argument = $arguments[$position] ?? [];

        return ($argument[1]->id ?? null) === ord(':') ? [] : $argument;
    }

    /** @param list<PhpToken> $tokens */
    private function groupHasPrefix(array $tokens): bool
    {
        $prefix = $this->arrayOption($tokens, 'prefix');

        return $prefix !== null && $this->nonEmptyPrefix($prefix);
    }

    /** @param list<PhpToken> $tokens */
    private function nonEmptyPrefix(array $tokens): bool
    {
        return count($tokens) === 1 && ($literal = $this->literal($tokens[0])) !== null && trim($literal, '/') !== '';
    }

    private function literal(PhpToken $token): ?string
    {
        if ($token->id !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        $value = substr($token->text, 1, -1);

        return str_starts_with($token->text, '"') ? stripcslashes($value) : str_replace(['\\\\', "\\'"], ['\\', "'"], $value);
    }
}
