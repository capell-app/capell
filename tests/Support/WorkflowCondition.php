<?php

declare(strict_types=1);

/**
 * Evaluates the subset of GitHub Actions expression syntax that workflow job
 * conditions use, so tests can assert when a job runs instead of matching the
 * condition's source text.
 */
final class WorkflowConditionEvaluator
{
    /** @var list<string> */
    private array $tokens = [];

    private int $position = 0;

    /**
     * @param  array<string, mixed>  $context  dotted names such as `github.event_name`
     */
    public function __construct(private readonly array $context) {}

    public function evaluate(string $condition): bool
    {
        $condition = trim($condition);

        if (preg_match('/^\$\{\{(.*)\}\}$/s', $condition, $wrapped) === 1) {
            $condition = $wrapped[1];
        }

        preg_match_all("/'(?:[^']|'')*'|&&|\\|\\||==|!=|[()!]|[A-Za-z_][A-Za-z0-9_.\\-]*(?:\\(\\))?/", $condition, $matches);
        $this->tokens = $matches[0];
        $this->position = 0;

        return $this->truthy($this->parseOr());
    }

    private function parseOr(): mixed
    {
        $left = $this->parseAnd();

        while ($this->peek() === '||') {
            $this->position++;
            $right = $this->parseAnd();
            $left = $this->truthy($left) || $this->truthy($right);
        }

        return $left;
    }

    private function parseAnd(): mixed
    {
        $left = $this->parseComparison();

        while ($this->peek() === '&&') {
            $this->position++;
            $right = $this->parseComparison();
            $left = $this->truthy($left) && $this->truthy($right);
        }

        return $left;
    }

    private function parseComparison(): mixed
    {
        $left = $this->parseNot();

        while (in_array($this->peek(), ['==', '!='], true)) {
            $operator = $this->tokens[$this->position++];
            $right = $this->parseNot();
            $left = $operator === '==' ? $left === $right : $left !== $right;
        }

        return $left;
    }

    private function parseNot(): mixed
    {
        if ($this->peek() === '!') {
            $this->position++;

            return ! $this->truthy($this->parseNot());
        }

        return $this->parsePrimary();
    }

    private function parsePrimary(): mixed
    {
        $token = $this->tokens[$this->position++] ?? throw new UnexpectedValueException('Unexpected end of workflow condition.');

        if ($token === '(') {
            $value = $this->parseOr();
            $this->position++;

            return $value;
        }

        if (str_starts_with($token, "'")) {
            return str_replace("''", "'", substr($token, 1, -1));
        }

        return match ($token) {
            'true', 'always()' => true,
            'false' => false,
            'null' => null,
            default => $this->lookup($token),
        };
    }

    private function lookup(string $name): mixed
    {
        if (str_ends_with($name, '()')) {
            throw new UnexpectedValueException(sprintf('Unsupported workflow function [%s].', $name));
        }

        return $this->context[$name] ?? null;
    }

    private function peek(): ?string
    {
        return $this->tokens[$this->position] ?? null;
    }

    private function truthy(mixed $value): bool
    {
        return ! in_array($value, [null, '', false, 0, '0'], true);
    }
}

/**
 * @param  array<string, mixed>  $context  dotted names such as `github.event_name`
 */
function evaluateWorkflowCondition(string $condition, array $context): bool
{
    return new WorkflowConditionEvaluator($context)->evaluate($condition);
}
