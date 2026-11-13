<?php

declare(strict_types=1);

use Capell\Core\Contracts\Security\SanitizesPublicHtml;
use Capell\Core\Enums\HtmlSanitizerProfile;
use Capell\Core\Support\Security\HtmlSanitizerProfileExtension;
use Capell\Core\Support\Security\HtmlSanitizerProfileRegistry;
use Capell\Core\Support\Security\PublicHtmlSanitizer;

function profileSanitizer(?HtmlSanitizerProfileRegistry $registry = null): PublicHtmlSanitizer
{
    return new PublicHtmlSanitizer(profiles: $registry ?? new HtmlSanitizerProfileRegistry);
}

dataset('html profiles', [
    'strict' => [HtmlSanitizerProfile::Strict],
    'rich text' => [HtmlSanitizerProfile::RichText],
    'comment' => [HtmlSanitizerProfile::Comment],
    'embed' => [HtmlSanitizerProfile::Embed],
]);

it('never lets script, handlers, inline styles or script URLs through any profile', function (HtmlSanitizerProfile $profile): void {
    $payload = implode('', [
        '<p onclick="steal()" style="position:fixed;inset:0">Kept text</p>',
        '<script>alert(1)</script><style>body{display:none}</style>',
        '<a href="javascript:alert(1)" onmouseover="x()">Link</a>',
        '<a href=" JaVaScRiPt:alert(1)">Spaced</a>',
        '<a href="data:text/html;base64,PHNjcmlwdD4=">Data</a>',
        '<a href="vbscript:msgbox(1)">VB</a>',
        '<img src="javascript:alert(1)" onerror="x()">',
        '<img src="data:image/svg+xml,<svg onload=alert(1)>">',
        '<iframe srcdoc="<script>alert(1)</script>"></iframe>',
        '<iframe src="javascript:alert(1)"></iframe>',
        '<iframe src="https://evil.example/frame"></iframe>',
        '<object data="x.swf"></object><embed src="x.swf"><form action="/x"><input name="q"></form>',
        '<svg><script>alert(1)</script></svg><math><mi>x</mi></math>',
        '<div x-data="{}" x-on:click="steal()" @click="steal()" wire:click="steal" hx-get="/x">Behaviour</div>',
        '<base href="https://evil.example/"><meta http-equiv="refresh" content="0;url=//evil.example"><link rel="stylesheet" href="//evil.example/x.css">',
        '<p name="CapellPrivacy" id="CapellPrivacy">Clobber</p><h2 id="Livewire">Heading</h2>',
    ]);

    $sanitized = profileSanitizer()->sanitize($payload, $profile);

    expect($sanitized)
        ->toContain('Kept text')
        ->not->toMatch('/<script\b/i')
        ->not->toMatch('/<style\b/i')
        ->not->toMatch('/\son[a-z]+\s*=/i')
        ->not->toMatch('/\sstyle\s*=/i')
        ->not->toMatch('/javascript:|vbscript:|data:/i')
        ->not->toContain('srcdoc')
        ->not->toContain('evil.example')
        ->not->toMatch('/<(object|embed|form|input|svg|math|base|meta|link)\b/i')
        ->not->toMatch('/\s(x-data|x-on:click|@click|wire:click|hx-get|name)\s*=/i')
        ->not->toContain('id="CapellPrivacy"')
        ->not->toContain('id="Livewire"');
})->with('html profiles');

it('keeps the strict profile identical to the default call', function (): void {
    $html = '<h2 class="lead" id="intro">Title</h2><figure><img src="/a.png" alt="A"><figcaption>Caption</figcaption></figure>';

    expect(profileSanitizer()->sanitize($html))
        ->toBe(profileSanitizer()->sanitize($html, HtmlSanitizerProfile::Strict))
        ->toBe('<h2>Title</h2><img src="/a.png" alt="A">Caption');
});

it('lets rich text keep figures, validated classes and heading ids', function (): void {
    $html = '<h2 id="getting-started" class="text-lg font-bold">Start</h2>'
        . '<p id="not-here" class="lead" >Body <mark>marked</mark> and <sup>1</sup></p>'
        . '<figure><img src="/a.png" alt="A" width="640" height="abc"><figcaption>Caption</figcaption></figure>'
        . '<table><tr><th scope="col" colspan="2">H</th><td rowspan="0">C</td></tr></table>';

    expect(profileSanitizer()->sanitize($html, HtmlSanitizerProfile::RichText))
        ->toBe('<h2 id="getting-started" class="text-lg font-bold">Start</h2>'
            . '<p class="lead">Body <mark>marked</mark> and <sup>1</sup></p>'
            . '<figure><img src="/a.png" alt="A" width="640"><figcaption>Caption</figcaption></figure>'
            . '<table><tr><th scope="col" colspan="2">H</th><td>C</td></tr></table>');
});

it('drops class tokens that could break out of the attribute or carry CSS escapes', function (): void {
    expect(profileSanitizer()->sanitize('<p class="ok \\61 bad&quot;x md:px-4 w-[50%]">x</p>', HtmlSanitizerProfile::RichText))
        ->toBe('<p class="ok md:px-4 w-[50%]">x</p>');
});

it('limits comments to text formatting and marks visitor links as user generated', function (): void {
    $html = '<h1 id="x">Heading</h1><p class="big">Nice <a href="https://example.com/post">post</a></p><img src="/track.gif"><table><tr><td>T</td></tr></table>';

    expect(profileSanitizer()->sanitize($html, HtmlSanitizerProfile::Comment))
        ->toBe('Heading<p>Nice <a href="https://example.com/post" rel="nofollow ugc noopener noreferrer" target="_blank">post</a></p>T');
});

it('frames only HTTPS allowlisted hosts and sandboxes every frame', function (): void {
    $html = '<iframe src="https://www.youtube-nocookie.com/embed/abc" title="Video" allowfullscreen="true" style="x" sandbox="allow-top-navigation" allow="camera"><p>fallback</p></iframe>'
        . '<iframe src="http://www.youtube.com/embed/abc"></iframe>'
        . '<iframe src="https://user@www.youtube.com/embed/abc"></iframe>'
        . '<iframe src="https://www.youtube.com:8443/embed/abc"></iframe>'
        . '<iframe src="https://www.youtube.com.evil.example/embed"></iframe>';

    $sanitized = profileSanitizer()->sanitize($html, HtmlSanitizerProfile::Embed);

    expect($sanitized)
        ->toBe('<iframe src="https://www.youtube-nocookie.com/embed/abc" title="Video" allowfullscreen="" sandbox="allow-scripts allow-same-origin allow-presentation allow-popups allow-popups-to-escape-sandbox" referrerpolicy="strict-origin-when-cross-origin" loading="lazy"></iframe>');

    expect(profileSanitizer()->sanitize($html, HtmlSanitizerProfile::RichText))->not->toContain('<iframe');
});

it('lets a package extend a profile with safe markup and embed hosts', function (): void {
    $registry = new HtmlSanitizerProfileRegistry;
    $registry->extend(HtmlSanitizerProfile::RichText, new HtmlSanitizerProfileExtension(['details' => ['open'], 'summary' => []]));
    $registry->extend(HtmlSanitizerProfile::Embed, new HtmlSanitizerProfileExtension(embedHosts: ['embed.example.org']));

    $sanitizer = profileSanitizer($registry);

    expect($sanitizer->sanitize('<details open onclick="x()"><summary>More</summary>Body</details>', HtmlSanitizerProfile::RichText))
        ->toBe('<details open>' . '<summary>More</summary>Body</details>')
        ->and($sanitizer->sanitize('<details open><summary>More</summary></details>', HtmlSanitizerProfile::Embed))
        ->toBe('<details open><summary>More</summary></details>')
        ->and($sanitizer->sanitize('<details open><summary>More</summary></details>', HtmlSanitizerProfile::Comment))
        ->toBe('More')
        ->and($sanitizer->sanitize('<iframe src="https://embed.example.org/x"></iframe>', HtmlSanitizerProfile::Embed))
        ->toContain('src="https://embed.example.org/x"');
});

it('refuses extensions that would bypass a profile', function (callable $extend): void {
    expect($extend)->toThrow(InvalidArgumentException::class);
})->with([
    'script element' => [fn (): HtmlSanitizerProfileExtension => new HtmlSanitizerProfileExtension(['script' => []])],
    'iframe element' => [fn (): HtmlSanitizerProfileExtension => new HtmlSanitizerProfileExtension(['iframe' => ['src']])],
    'custom element' => [fn (): HtmlSanitizerProfileExtension => new HtmlSanitizerProfileExtension(['capell-widget' => []])],
    'event handler' => [fn (): HtmlSanitizerProfileExtension => new HtmlSanitizerProfileExtension(['p' => ['onclick']])],
    'inline style' => [fn (): HtmlSanitizerProfileExtension => new HtmlSanitizerProfileExtension(['p' => ['style']])],
    'unvalidated url' => [fn (): HtmlSanitizerProfileExtension => new HtmlSanitizerProfileExtension(['p' => ['href']])],
    'srcset' => [fn (): HtmlSanitizerProfileExtension => new HtmlSanitizerProfileExtension(['img' => ['srcset']])],
    'alpine binding' => [fn (): HtmlSanitizerProfileExtension => new HtmlSanitizerProfileExtension(['div' => ['x-html']])],
    'colon binding' => [fn (): HtmlSanitizerProfileExtension => new HtmlSanitizerProfileExtension(['div' => [':href']])],
    'clobbering name' => [fn (): HtmlSanitizerProfileExtension => new HtmlSanitizerProfileExtension(['p' => ['name']])],
    'wildcard host' => [fn (): HtmlSanitizerProfileExtension => new HtmlSanitizerProfileExtension(embedHosts: ['*.example.org'])],
    'ip host' => [fn (): HtmlSanitizerProfileExtension => new HtmlSanitizerProfileExtension(embedHosts: ['127.0.0.1'])],
    'hosts outside embed' => [fn () => (new HtmlSanitizerProfileRegistry)->extend(HtmlSanitizerProfile::RichText, new HtmlSanitizerProfileExtension(embedHosts: ['embed.example.org']))],
    'svg upload' => [fn () => (new HtmlSanitizerProfileRegistry)->extend(HtmlSanitizerProfile::SvgUpload, new HtmlSanitizerProfileExtension)],
]);

it('resolves the contract to the profile sanitiser with the booted registry', function (): void {
    resolve(HtmlSanitizerProfileRegistry::class)->extend(HtmlSanitizerProfile::Comment, new HtmlSanitizerProfileExtension(['h3' => []]));

    expect(resolve(SanitizesPublicHtml::class)->sanitize('<h3>Kept</h3>', HtmlSanitizerProfile::Comment))->toBe('<h3>Kept</h3>')
        ->and((new PublicHtmlSanitizer)->sanitize('<h3>Kept</h3>', HtmlSanitizerProfile::Comment))->toBe('<h3>Kept</h3>');
});

it('sanitises SVG uploads through the svg profile and fails closed', function (): void {
    expect(profileSanitizer()->sanitize('<svg xmlns="http://www.w3.org/2000/svg" onload="x()"><path d="M0 0"/></svg>', HtmlSanitizerProfile::SvgUpload))
        ->toBe('<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>')
        ->and(profileSanitizer()->sanitize('<p>not svg</p>', HtmlSanitizerProfile::SvgUpload))->toBe('');
});
