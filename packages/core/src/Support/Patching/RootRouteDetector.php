<?php

declare(strict_types=1);

namespace Capell\Core\Support\Patching;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use PhpToken;
use ReflectionClass;

/** @internal Detects possible application roots without executing application code. */
final class RootRouteDetector
{
    /** @var array<int, int> */
    private array $ends = [];

    /** @var array<string, int> */
    private array $functions = [];

    /** @var array<int, true> */
    private array $executing = [];

    /** @var array<int, int> */
    private array $starts = [];

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
                    $this->starts[$index] = $start;
                }
            }
        }
    }

    public function hasRootRoute(): bool
    {
        $this->collectFunctions();

        return $this->scan(0, count($this->tokens), false);
    }

    /**
     * @param  list<string>  $routers
     * @param  array<string, int>  $callbacks
     */
    private function scan(int $start, int $end, bool $prefixed, array $routers = [], array $callbacks = []): bool
    {
        for ($index = $start; $index < $end; $index++) {
            $token = $this->tokens[$index];
            if ($token->id === T_VARIABLE && ($this->tokens[$index + 1]->text ?? null) === '=') {
                $value = $this->unwrapStart($index + 2);
                unset($callbacks[$token->text]);
                $routers = array_values(array_diff($routers, [$token->text]));
                if ($this->tokens[$value]->is([T_FUNCTION, T_FN])) {
                    $callbacks[$token->text] = $value;
                } elseif (isset($callbacks[$this->tokens[$value]->text])) {
                    $callbacks[$token->text] = $callbacks[$this->tokens[$value]->text];
                }

                if ($this->isRouter($value, $routers)) {
                    $routers[] = $token->text;
                }
            }

            if ($token->is([T_FUNCTION, T_FN]) && $this->isCallableDefinition($index)) {
                [, $close] = $this->callableBody($index, $end);
                $last = $token->id === T_FN ? $close - 1 : $close;
                if ($this->isInvoked($last, $end) && $this->execute($index, $prefixed, $routers, $callbacks, arguments: $this->invocationArguments($last))) {
                    return true;
                }

                $index = $last;

                continue;
            }

            $previous = $this->tokens[$index - 1]->id ?? null;
            if (! in_array($previous, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW], true)) {
                $name = strtolower(ltrim($token->text, '@\\'));
                if (in_array($name, ['call_user_func', 'call_user_func_array'], true) && $this->isInvoked($index, $end)) {
                    $close = $this->ends[$index + 1];
                    $arguments = $this->arguments($index + 2, $close);
                    $values = array_slice($arguments, 1);
                    if ($name === 'call_user_func_array') {
                        $array = $arguments[1] ?? [];
                        $position = isset($array[0]) ? array_search($array[0], $this->tokens, true) : false;
                        if ($position === false || $this->tokens[$position]->id !== ord('[')) {
                            return true;
                        }

                        $values = $this->arguments($position + 1, $this->ends[$position]);
                    }

                    if ($this->executeCallback($this->argument($arguments, 'callback', 0), $prefixed, $routers, $callbacks, arguments: $values)) {
                        return true;
                    }

                    $index = $close;

                    continue;
                }

                $callable = $callbacks[$token->text] ?? $this->functions[$name] ?? null;
                if ($callable !== null && $this->isInvoked($index, $end)
                    && $this->execute($callable, $prefixed, $routers, $callbacks, arguments: $this->invocationArguments($index))) {
                    return true;
                }
            }

            if ($token->is([T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM]) && ($this->tokens[$index - 1]->id ?? null) !== T_DOUBLE_COLON) {
                while ($index < $end && ! $this->tokens[$index]->is([ord('{'), ord(';')])) {
                    $index = isset($this->ends[$index]) ? $this->ends[$index] + 1 : $index + 1;
                }

                $index = $this->ends[$index] ?? $index;

                continue;
            }

            if ($token->is([T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE])) {
                $fileEnd = $this->expressionEnd($index, $end);
                $file = array_slice($this->tokens, $index + 1, $fileEnd - $index);
                while (($file[0]->id ?? null) === ord('(') && ($file[array_key_last($file)]->id ?? null) === ord(')')) {
                    $file = array_slice($file, 1, -1);
                }

                // Breeze's standard auth include has no homepage. Every other
                // file is opaque: do not execute host code or assume it is safe.
                if (count($file) !== 3 || $file[0]->id !== T_DIR || $file[1]->text !== '.' || $this->literal($file[2]) !== '/auth.php') {
                    return true;
                }
            }

            $volt = strtolower($token->text) === 'livewire\\volt\\volt' && ($this->tokens[$index + 1]->id ?? null) === T_DOUBLE_COLON;
            $facade = $token->text === 'Route' && ($this->tokens[$index + 1]->id ?? null) === T_DOUBLE_COLON;
            $router = $token->id === T_VARIABLE && in_array($token->text, $routers, true)
                && ($this->tokens[$index + 1]->id ?? null) === T_OBJECT_OPERATOR;
            if (! $facade && ! $router && ! $volt) {
                continue;
            }

            $cursor = $index + 2;
            $routePrefixed = $prefixed;
            $root = false;
            $registered = false;
            $resourceMethods = null;
            $resourceOptions = [];
            while (($this->tokens[$cursor + 1]->id ?? null) === ord('(')) {
                if (! $this->isInvoked($cursor, $end)) {
                    break;
                }

                $method = strtolower($this->tokens[$cursor]->text);
                $close = $this->ends[$cursor + 1];
                $arguments = $this->arguments($cursor + 2, $close);
                // Argument expressions run before registration; closure bodies
                // remain deferred unless a group explicitly executes them.
                if ($this->scan($cursor + 2, $close, $prefixed, $routers, $callbacks)) {
                    return true;
                }

                if ($volt && ! $registered && $method !== 'route') {
                    break;
                }

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
                    $callback = $this->argument($arguments, count($arguments) > 1 ? 'routes' : 'callback', count($arguments) > 1 ? 1 : 0);
                    if ($this->executeCallback($callback, $routePrefixed || $this->groupHasPrefix($attributes), $routers, $callbacks, true)) {
                        return true;
                    }
                } elseif (($volt && $method === 'route') || in_array($method, ['inertia', 'livewire', 'get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'match', 'addroute', 'view', 'redirect', 'permanentredirect'], true)) {
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
                } elseif (in_array($method, ['fallback', 'setroutes', 'setcompiledroutes'], true)) {
                    if ($method !== 'fallback') {
                        return true;
                    }

                    $registered = true;
                    $root = ! $routePrefixed;
                } elseif (! $registered && ! in_array($method, ['as', 'can', 'metadata', 'missing', 'middleware', 'withoutmiddleware', 'name', 'domain', 'controller', 'where', 'wherenumber', 'wherealpha', 'wherealphanumeric', 'whereuuid', 'whereulid', 'wherein', 'scopebindings', 'withoutscopedbindings', 'namespace'], true) && ! $this->isFrameworkRouteMethod($method)) {
                    // Unknown macros have no guaranteed URI argument or return
                    // type, and may replace a prefix internally. Fail closed.
                    return true;
                }

                $index = $close;
                $close = $this->expressionClose($close);
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
        $next = $this->invocationOpen($close);

        // A sole ellipsis creates a first-class callable without executing it.
        return $next < $end && ($this->tokens[$next]->id ?? null) === ord('(')
            && (($this->tokens[$next + 1]->id ?? null) !== T_ELLIPSIS || ($this->tokens[$next + 2]->id ?? null) !== ord(')'));
    }

    private function invocationOpen(int $close): int
    {
        $next = $this->expressionClose($close) + 1;
        if (($this->tokens[$next]->id ?? null) === T_OBJECT_OPERATOR
            && strtolower($this->tokens[$next + 1]->text ?? '') === '__invoke') {
            $next += 2;
        }

        return $next;
    }

    /** @return list<list<PhpToken>> */
    private function invocationArguments(int $close): array
    {
        $open = $this->invocationOpen($close);

        return $this->arguments($open + 1, $this->ends[$open]);
    }

    /** Only cross expression wrappers, never another call's argument list. */
    private function expressionClose(int $close): int
    {
        while (($this->tokens[$close + 1]->id ?? null) === ord(')')) {
            $open = $this->starts[$close + 1];
            $previous = $this->tokens[$open - 1] ?? null;
            if ($previous !== null && ($previous->is([T_STRING, T_VARIABLE, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, ord(')'), ord(']')])
                || $previous->id === T_FUNCTION)) {
                break;
            }

            $close++;
        }

        return $close;
    }

    private function unwrapStart(int $index): int
    {
        while (($this->tokens[$index]->id ?? null) === ord('(')) {
            $index++;
        }

        return ($this->tokens[$index]->id ?? null) === T_STATIC ? $index + 1 : $index;
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

    /** @return array{int, int, int, int} Body start/end (exclusive), parameter start/end. */
    private function callableBody(int $index, int $end): array
    {
        $parameters = $index + 1;
        while ($parameters < $end && $this->tokens[$parameters]->id !== ord('(')) {
            $parameters++;
        }

        $parametersEnd = $this->ends[$parameters];
        if ($this->tokens[$index]->id === T_FN) {
            $close = $this->expressionEnd($index, $end);

            return [$this->arrowBody($index, $close), $close + 1, $parameters, $parametersEnd];
        }

        $body = $parametersEnd + 1;
        while ($body < $end && ! $this->tokens[$body]->is([ord('{'), ord(';')])) {
            $body = isset($this->ends[$body]) ? $this->ends[$body] + 1 : $body + 1;
        }

        return [$body + 1, $this->ends[$body] ?? $body, $parameters, $parametersEnd];
    }

    private function collectFunctions(): void
    {
        $namespace = '';
        $namespaceDepth = 0;
        $depth = 0;
        $end = count($this->tokens);
        for ($index = 0; $index < $end; $index++) {
            $token = $this->tokens[$index];
            if ($token->id === T_NAMESPACE) {
                $namespace = '';
                for ($next = $index + 1; isset($this->tokens[$next]) && ! $this->tokens[$next]->is([ord(';'), ord('{')]); $next++) {
                    $namespace .= $this->tokens[$next]->text;
                }

                $namespaceDepth = $depth + (($this->tokens[$next]->id ?? null) === ord('{') ? 1 : 0);
            } elseif ($token->is([T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM]) && ($this->tokens[$index - 1]->id ?? null) !== T_DOUBLE_COLON) {
                while ($index < $end && ! $this->tokens[$index]->is([ord('{'), ord(';')])) {
                    $index = isset($this->ends[$index]) ? $this->ends[$index] + 1 : $index + 1;
                }

                $index = $this->ends[$index] ?? $index;
            } elseif ($token->is([T_FUNCTION, T_FN]) && $this->isCallableDefinition($index)) {
                $name = $index + 1;
                if (($this->tokens[$name]->text ?? null) === '&') {
                    $name++;
                }

                if ($token->id === T_FUNCTION && ($this->tokens[$name]->id ?? null) === T_STRING) {
                    $this->functions[strtolower(ltrim($namespace . '\\' . $this->tokens[$name]->text, '\\'))] = $index;
                }

                [, $close] = $this->callableBody($index, $end);
                $index = $token->id === T_FN ? $close - 1 : $close;

                continue;
            }

            if ($token->is([ord('{'), T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->id === ord('}')) {
                $depth--;
                if ($depth < $namespaceDepth) {
                    $namespace = '';
                    $namespaceDepth = 0;
                }
            }
        }
    }

    private function isCallableDefinition(int $index): bool
    {
        $next = $index + 1;
        if (($this->tokens[$next]->text ?? null) === '&') {
            $next++;
        }

        if (($this->tokens[$next]->id ?? null) === T_STRING) {
            $next++;
        }

        return ($this->tokens[$next]->id ?? null) === ord('(');
    }

    /**
     * @param  list<PhpToken>  $callback
     * @param  list<string>  $routers
     * @param  array<string, int>  $callbacks
     * @param  list<list<PhpToken>>  $arguments
     */
    private function executeCallback(array $callback, bool $prefixed, array $routers, array $callbacks, bool $group = false, array $arguments = []): bool
    {
        while (($callback[0]->id ?? null) === ord('(') && ($callback[array_key_last($callback)]->id ?? null) === ord(')')) {
            $callback = array_slice($callback, 1, -1);
        }

        if (($callback[0]->id ?? null) === T_STATIC) {
            $callback = array_slice($callback, 1);
        }

        $first = $callback[0] ?? null;
        $callable = $first === null ? null : ($callbacks[$first->text] ?? $this->functions[strtolower(ltrim($this->literal($first) ?? $first->text, '@\\'))] ?? null);
        if ($first !== null && $first->is([T_FUNCTION, T_FN])) {
            $position = array_search($first, $this->tokens, true);
            $callable = $position === false ? null : $position;
        }

        // Unknown callbacks/files can register routes. Never enable a competing
        // frontend homepage merely because their implementation is unavailable.
        return $callable === null || $this->execute($callable, $prefixed, $routers, $callbacks, $group, $arguments);
    }

    /**
     * @param  list<string>  $routers
     * @param  array<string, int>  $callbacks
     * @param  list<list<PhpToken>>  $arguments
     */
    private function execute(int $callable, bool $prefixed, array $routers, array $callbacks, bool $group = false, array $arguments = []): bool
    {
        if (isset($this->executing[$callable])) {
            return true;
        }

        [$body, $end, $parameters, $parametersEnd] = $this->callableBody($callable, count($this->tokens));
        foreach ($this->arguments($parameters + 1, $parametersEnd) as $position => $parameter) {
            foreach ($parameter as $token) {
                if ($token->id !== T_VARIABLE) {
                    continue;
                }

                $argument = $this->argument($arguments, ltrim($token->text, '$'), $position);
                $value = isset($argument[0]) ? array_search($argument[0], $this->tokens, true) : false;
                if (($group && $position === 0) || ($value !== false && $this->isRouter($this->unwrapStart($value), $routers))) {
                    $routers[] = $token->text;
                }

                break;
            }
        }

        $this->executing[$callable] = true;
        try {
            return $this->scan($body, $end, $prefixed, $routers, $callbacks);
        } finally {
            unset($this->executing[$callable]);
        }
    }

    /** @param list<string> $routers */
    private function isRouter(int $index, array $routers): bool
    {
        $token = $this->tokens[$index];
        if (in_array($token->text, $routers, true)) {
            return true;
        }

        if ($token->text === 'Route' && ($this->tokens[$index + 1]->id ?? null) === T_DOUBLE_COLON
            && strtolower($this->tokens[$index + 2]->text ?? '') === 'getfacaderoot') {
            return true;
        }

        if (in_array(strtolower(ltrim($token->text, '\\')), ['app', 'resolve'], true)
            && ($this->tokens[$index + 1]->id ?? null) === ord('(')) {
            $argument = $this->tokens[$index + 2] ?? null;
            $close = $this->ends[$index + 1];
            if ($argument?->id === ord(')') && ($this->tokens[$close + 1]->id ?? null) === T_OBJECT_OPERATOR
                && in_array(strtolower($this->tokens[$close + 2]->text ?? ''), ['make', 'get'], true)
                && ($this->tokens[$close + 3]->id ?? null) === ord('(')) {
                $argument = $this->tokens[$close + 4] ?? null;
            }

            return $argument !== null && ($this->literal($argument) === 'router'
                || strtolower(ltrim($argument->text, '\\')) === 'illuminate\\routing\\router');
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

    /**
     * Router and Route declare their fluent API; anything else called on them can only come from a macro.
     */
    private function isFrameworkRouteMethod(string $method): bool
    {
        if (new ReflectionClass(Router::class)->hasMethod($method)) {
            return true;
        }

        return new ReflectionClass(Route::class)->hasMethod($method);
    }
}
