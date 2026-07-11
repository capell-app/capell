<?php

declare(strict_types=1);

namespace Capell\Frontend\Support;

use Illuminate\Contracts\Support\Htmlable;
use Stringable;

final readonly class SafeHtml implements Htmlable, Stringable
{
    private function __construct(
        private string $html,
    ) {}

    public function __toString(): string
    {
        return $this->html;
    }

    /**
     * @param  callable(string): string  $sanitizer
     */
    public static function sanitize(string $html, callable $sanitizer): self
    {
        return new self($sanitizer($html));
    }

    public function toHtml(): string
    {
        return $this->html;
    }

    public function isEmpty(): bool
    {
        return $this->html === '';
    }
}
