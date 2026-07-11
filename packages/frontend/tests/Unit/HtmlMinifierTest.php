<?php

declare(strict_types=1);

use Capell\Frontend\Support\Html\HtmlMinifier;

it('minifies html while preserving explicit attributes and tags', function (): void {
    $html = <<<'HTML'
        <section class="b a" data-value="keep me">
            <p title="Hello world"> Hello world </p>
        </section>
        HTML;

    $minified = (new HtmlMinifier)->minify($html);

    expect($minified)
        ->toContain('<section class="b a" data-value="keep me">')
        ->toContain('<p title="Hello world"> Hello world </p>')
        ->not->toContain("\n");
});

it('returns an empty string for empty html', function (): void {
    expect((new HtmlMinifier)->minify(''))->toBe('');
});

it('preserves absolute http and https urls', function (): void {
    $html = '<a href="http://capell-ruby.test/en">Home</a><script type="module">import("https://cdn.example.test/app.js")</script>';

    $minified = (new HtmlMinifier)->minify($html);

    expect($minified)
        ->toContain('href="http://capell-ruby.test/en"')
        ->toContain('import("https://cdn.example.test/app.js")');
});

it('preserves alpine expression line breaks inside attributes', function (): void {
    $html = <<<'HTML'
        <section
            x-data="{
                matchesQuery(search) {
                    const term = this.query.trim().toLowerCase()

                    return term === '' || search.includes(term)
                },
            }"
            x-on:click="
                active = 'Package'
                scheduleSearchLog()
            "
        ></section>
        HTML;

    $minified = (new HtmlMinifier)->minify($html);

    expect($minified)
        ->toMatch("/const term = this\.query\.trim\(\)\.toLowerCase\(\)\\s*\\R\\s*return term === '' \|\| search\.includes\(term\)/")
        ->toMatch("/active = 'Package'\\s*\\R\\s*scheduleSearchLog\(\)/")
        ->not->toContain('const term = this.query.trim().toLowerCase() return')
        ->not->toContain("active = 'Package' scheduleSearchLog()");
});
