<?php

declare(strict_types=1);

use Capell\Frontend\Actions\RenderHtmlContentAction;

it('sanitizes html without evaluating blade by default', function (): void {
    config()->set('capell-frontend.render_html_content_with_blade', false);

    $html = '<p>Hello <strong>reader</strong></p><script>alert("xss")</script>@php echo "executed"; @endphp';

    expect(RenderHtmlContentAction::run($html)->toHtml())->toBe('<p>Hello <strong>reader</strong></p>&#64;php echo &#34;executed&#34;; &#64;endphp');
});

it('preserves sanitized html longer than the sanitizer default input limit', function (): void {
    config()->set('capell-frontend.render_html_content_with_blade', false);

    $body = str_repeat('Long content ', 2200);
    $html = '<p>' . $body . '</p><script>alert("xss")</script>';

    expect(RenderHtmlContentAction::run($html)->toHtml())
        ->toBe('<p>' . $body . '</p>')
        ->not->toContain('script');
});

it('interpolates simple scalar tokens without evaluating expressions or directives', function (): void {
    config()->set('capell-frontend.render_html_content_with_blade', false);

    $html = '<p>{{ title }}</p><p>{{ page.title }}</p><p>{{ $title }}</p>@php echo "executed"; @endphp';

    expect(RenderHtmlContentAction::run($html, [
        'title' => 'Safe title',
        'page' => ['title' => 'Nested title'],
    ])->toHtml())->toBe('<p>Safe title</p><p>Nested title</p><p>{{ $title }}</p>&#64;php echo &#34;executed&#34;; &#64;endphp');
});

it('leaves unknown simple tokens inert', function (): void {
    config()->set('capell-frontend.render_html_content_with_blade', false);

    expect(RenderHtmlContentAction::run('<p>{{ missing }}</p>', ['title' => 'Safe title'])->toHtml())->toBe('<p>{{ missing }}</p>');
});

it('does not evaluate blade even if the legacy compatibility flag is enabled', function (): void {
    config()->set('capell-frontend.render_html_content_with_blade', true);

    expect(RenderHtmlContentAction::run('<p>Hello {{ $name }}</p>', ['name' => 'Ada'])->toHtml())->toBe('<p>Hello {{ $name }}</p>');
});

it('keeps dollar-prefixed blade variables inert', function (): void {
    config()->set('capell-frontend.render_html_content_with_blade', false);

    expect(RenderHtmlContentAction::run('<p>{{ $name }}</p>', ['name' => 'Ada'])->toHtml())
        ->toBe('<p>{{ $name }}</p>');

    config()->set('capell-frontend.render_html_content_with_blade', true);

    expect(RenderHtmlContentAction::run('<p>{{ $name }}</p>', ['name' => 'Ada'])->toHtml())
        ->toBe('<p>{{ $name }}</p>');
});

it('does not traverse objects when interpolating public html tokens', function (): void {
    $context = [
        'page' => new class
        {
            public int $id = 42;
        },
    ];

    expect(RenderHtmlContentAction::run('<p>{{ page.id }}</p>', $context)->toHtml())
        ->toBe('<p>{{ page.id }}</p>');
});
