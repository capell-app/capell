<?php

declare(strict_types=1);

use Capell\Core\Enums\HtmlSanitizerProfile;
use Capell\Core\Support\Security\HtmlSanitizerProfileExtension;
use Capell\Core\Support\Security\HtmlSanitizerProfileRegistry;
use Capell\Frontend\Actions\RenderHtmlContentAction;

it('sanitizes html without evaluating blade by default', function (): void {
    config()->set('capell-frontend.render_html_content_with_blade', false);

    $html = '<p>Hello <strong>reader</strong></p><script>alert("xss")</script>@php echo "executed"; @endphp';

    expect(RenderHtmlContentAction::run($html)->toHtml())->toBe('<p>Hello <strong>reader</strong></p>@php echo "executed"; @endphp');
});

it('preserves sanitized html longer than the sanitizer default input limit', function (): void {
    config()->set('capell-frontend.render_html_content_with_blade', false);

    $body = str_repeat('Long content ', 2200);
    $html = '<p>' . $body . '</p><script>alert("xss")</script>';

    expect(RenderHtmlContentAction::run($html)->toHtml())
        ->toBe('<p>' . $body . '</p>')
        ->not->toContain('script');
});

it('sanitises through the rich text profile, keeping classes and dropping undeclared attributes', function (): void {
    $html = '<p class="lead" data-track="x" style="color:red" onclick="steal()">Hello <a href="javascript:alert(1)">link</a></p>';

    $rendered = RenderHtmlContentAction::run($html)->toHtml();

    expect($rendered)->toContain('<p class="lead">Hello')
        ->not->toContain('data-track')
        ->not->toContain('style=')
        ->not->toContain('onclick')
        ->not->toContain('javascript:');
});

it('keeps extra markup a package declares through a rich text profile extension', function (): void {
    resolve(HtmlSanitizerProfileRegistry::class)->extend(
        HtmlSanitizerProfile::RichText,
        new HtmlSanitizerProfileExtension(['p' => ['data-track']]),
    );

    expect(RenderHtmlContentAction::run('<p data-track="hero">Hello</p>')->toHtml())
        ->toBe('<p data-track="hero">Hello</p>');
});
it('interpolates simple scalar tokens without evaluating expressions or directives', function (): void {
    config()->set('capell-frontend.render_html_content_with_blade', false);

    $html = '<p>{{ title }}</p><p>{{ page.title }}</p><p>{{ $title }}</p>@php echo "executed"; @endphp';

    expect(RenderHtmlContentAction::run($html, [
        'title' => 'Safe title',
        'page' => ['title' => 'Nested title'],
    ])->toHtml())->toBe('<p>Safe title</p><p>Nested title</p><p>{{ $title }}</p>@php echo "executed"; @endphp');
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
