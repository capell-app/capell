<?php

declare(strict_types=1);

namespace Capell\Core\Support\Patching;

use PhpToken;

/**
 * Only standalone, file-level route statements are removable. Token positions
 * let removal preserve every byte outside a stock welcome statement.
 */
final class RootRouteEditor
{
    /** @var list<non-empty-list<PhpToken>> */
    private readonly array $routes;

    private readonly RouteSourceTokens $source;

    public function __construct(private readonly string $content)
    {
        $this->source = new RouteSourceTokens($content);
        $this->routes = $this->rootRoutes();
    }

    public function hasRootRoute(): bool
    {
        return new RootRouteDetector($this->source->tokens)->hasRootRoute();
    }

    public function hasStockWelcomeRoute(): bool
    {
        return array_any($this->routes, $this->isStockWelcomeRoute(...));
    }

    public function removeStockWelcomeRoutes(): string
    {
        $content = $this->content;

        foreach (array_reverse($this->routes) as $tokens) {
            if (! $this->isStockWelcomeRoute($tokens)) {
                continue;
            }

            $first = $tokens[0];
            $last = $tokens[array_key_last($tokens)];
            $content = substr_replace($content, '', $first->pos, $last->pos + strlen($last->text) - $first->pos);
        }

        return $content;
    }

    /** @return list<non-empty-list<PhpToken>> */
    private function rootRoutes(): array
    {
        $routes = [];
        $statement = [];
        $depth = 0;
        $alternativeDepth = 0;
        $controlHeader = false;
        $awaitingControlBody = false;
        $namespaceHeader = false;

        foreach ($this->source->tokens as $token) {
            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG])) {
                continue;
            }

            if ($depth === 0 && $token->id === T_NAMESPACE) {
                $namespaceHeader = true;
                $statement = [];
            }

            if ($namespaceHeader) {
                if ($token->is([ord(';'), ord('{')])) {
                    $namespaceHeader = false;
                }

                continue;
            }

            if ($depth === 0 && $token->id === ord('}')) {
                $statement = [];

                continue;
            }

            // Alternative control syntax has no braces. Track the token
            // after each control header so nested case/else blocks stay scoped.
            if ($awaitingControlBody) {
                $awaitingControlBody = false;
                $controlHeader = false;
                if ($token->is(ord(':'))) {
                    $alternativeDepth++;
                    $statement = [];

                    continue;
                }
            }

            if ($depth === 0 && $token->is([T_IF, T_FOR, T_FOREACH, T_WHILE, T_SWITCH, T_DECLARE])) {
                $controlHeader = true;
            }

            $statement[] = $token;

            if (in_array($token->id, [ord('('), ord('['), ord('{'), T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE], true)) {
                $depth++;
            } elseif (in_array($token->id, [ord(')'), ord(']'), ord('}')], true)) {
                $depth--;
            }

            if ($depth === 0 && $token->is(ord(')')) && $controlHeader) {
                $awaitingControlBody = true;
            }

            if ($depth === 0 && $token->is([T_ENDIF, T_ENDFOR, T_ENDFOREACH, T_ENDWHILE, T_ENDSWITCH, T_ENDDECLARE])) {
                $alternativeDepth--;
            }

            if ($depth !== 0) {
                continue;
            }

            if (! in_array($token->id, [ord(';'), ord('}')], true)) {
                continue;
            }

            if ($alternativeDepth === 0 && $token->is(ord(';')) && $this->isRootRoute($statement)) {
                $routes[] = $statement;
            }

            $statement = [];
        }

        return $routes;
    }

    /** @param non-empty-list<PhpToken> $tokens */
    private function isRootRoute(array $tokens): bool
    {
        if (! in_array(strtolower(ltrim($tokens[0]->text, '\\')), ['route', 'illuminate\\support\\facades\\route'], true)
            || ($tokens[1]->text ?? null) !== '::'
            || ($tokens[3]->text ?? null) !== '(') {
            return false;
        }

        $method = strtolower($tokens[2]->text);
        if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'match', 'view', 'redirect', 'permanentredirect'], true)) {
            return false;
        }

        $uriIndex = 4;
        if ($method === 'match') {
            $depth = 0;
            for ($index = 4; isset($tokens[$index]); $index++) {
                $token = $tokens[$index];
                if ($token->is(ord(',')) && $depth === 0) {
                    $uriIndex = $index + 1;
                    break;
                }

                if (in_array($token->id, [ord('('), ord('['), ord('{')], true)) {
                    $depth++;
                } elseif (in_array($token->id, [ord(')'), ord(']'), ord('}')], true)) {
                    $depth--;
                }
            }
        }

        return in_array($tokens[$uriIndex]->text ?? null, ["'/'", '"/"'], true)
            && in_array($tokens[$uriIndex + 1]->text ?? null, [',', ')'], true);
    }

    /** @param non-empty-list<PhpToken> $tokens */
    private function isStockWelcomeRoute(array $tokens): bool
    {
        $parts = array_map(static fn (PhpToken $token): string => $token->text, $tokens);
        $parts[0] = 'Route';
        $statement = implode(' ', $parts);

        return preg_match(<<<'REGEX'
~\A Route \s* :: \s*
(?:
    get \s* \( \s* (?:'/'|"/") \s* , \s* (?:static \s+)?
    (?:
        fn \s* \( \s* \) \s* => \s* view \s* \( \s* (?:'welcome'|"welcome") \s* \)
        |
        function \s* \( \s* \) \s* \{ \s* return \s+ view \s* \( \s* (?:'welcome'|"welcome") \s* \) \s* ; \s* \}
    ) \s* \)
    |
    view \s* \( \s* (?:'/'|"/") \s* , \s* (?:'welcome'|"welcome") \s* \)
    (?: \s* -> \s* name \s* \( \s* (?:'home'|"home") \s* \) )?
) \s* ; \z~x
REGEX, $statement) === 1;
    }
}
