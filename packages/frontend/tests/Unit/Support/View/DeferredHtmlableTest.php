<?php

declare(strict_types=1);

use Capell\Frontend\Support\View\DeferredHtmlable;
use Illuminate\Contracts\Support\Htmlable;

it('implements htmlable interface', function (): void {
    $deferrable = new DeferredHtmlable(fn (): string => '<div>Content</div>');
    expect($deferrable)->toBeInstanceOf(Htmlable::class);
});

it('implements stringable interface', function (): void {
    $deferrable = new DeferredHtmlable(fn (): string => '<div>Content</div>');
    expect($deferrable)->toBeInstanceOf(Stringable::class);
});

it('evaluates closure when toHtml is called', function (): void {
    $called = false;
    $deferrable = new DeferredHtmlable(function () use (&$called): string {
        $called = true;

        return '<div>Content</div>';
    });

    expect($called)->toBeFalse();
    $html = $deferrable->toHtml();
    expect($called)->toBeTrue();
});

it('returns html from closure', function (): void {
    $html = '<div>Test Content</div>';
    $deferrable = new DeferredHtmlable(fn (): string => $html);

    expect($deferrable->toHtml())->toBe($html);
});

it('can be converted to string via __toString', function (): void {
    $html = '<p>Test</p>';
    $deferrable = new DeferredHtmlable(fn (): string => $html);

    expect((string) $deferrable)->toBe($html);
});

it('defers closure evaluation until first access', function (): void {
    $executions = 0;
    $deferrable = new DeferredHtmlable(function () use (&$executions): string {
        $executions++;

        return 'HTML';
    });

    expect($executions)->toBe(0);

    $deferrable->toHtml();
    expect($executions)->toBe(1);
});

it('evaluates closure each time toHtml is called', function (): void {
    $executions = 0;
    $deferrable = new DeferredHtmlable(function () use (&$executions): string {
        $executions++;

        return 'HTML';
    });

    $deferrable->toHtml();

    expect($executions)->toBe(1);

    $deferrable->toHtml();
    expect($executions)->toBe(2);
});

it('handles closures with complex logic', function (): void {
    $deferrable = new DeferredHtmlable(function (): string {
        $result = '';
        for ($i = 1; $i <= 3; $i++) {
            $result .= sprintf('<div>Item %d</div>', $i);
        }

        return $result;
    });

    $html = $deferrable->toHtml();
    expect($html)->toContain('Item 1');
    expect($html)->toContain('Item 2');
    expect($html)->toContain('Item 3');
});

it('preserves html entities and special characters', function (): void {
    $html = '<div>&lt;script&gt;alert("xss")&lt;/script&gt;</div>';
    $deferrable = new DeferredHtmlable(fn (): string => $html);

    expect($deferrable->toHtml())->toBe($html);
});
