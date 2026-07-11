<?php

declare(strict_types=1);

use Capell\Frontend\Actions\RenderContentAction;
use Capell\Frontend\Contracts\HtmlMinifier;

describe('RenderContentAction', function (): void {
    it('returns empty string for null content', function (): void {
        expect(RenderContentAction::run(null))->toBe('');
    });

    it('returns empty array for null content with asArray', function (): void {
        expect(RenderContentAction::run(null, null, [], false, false, true))->toBeArray()->toBeEmpty();
    });

    it('renders HTML content', function (): void {
        $html = '<div>Test</div>';
        expect(RenderContentAction::run($html))->toBe('<div>Test</div>');
    });

    it('renders HTML content with decodeEntities', function (): void {
        $html = '<div>&amp; &lt; &gt; &quot; &#39;</div>';
        $expected = '<div>& < > " \'</div>';
        $actual = RenderContentAction::run($html, null, [], false, true);
        expect($actual)->toBe($expected);
    });

    it('renders HTML content with stripTags', function (): void {
        $html = '<div>Test <span>Inner</span></div>';
        $actual = RenderContentAction::run($html, null, [], true);
        expect($actual)->toBe('Test Inner');
    });

    it('uses the configured html minifier contract when enabled', function (): void {
        app()->instance(HtmlMinifier::class, new class implements HtmlMinifier
        {
            public function minify(string $html): string
            {
                return 'minified:' . $html;
            }
        });

        expect(RenderContentAction::run('<div>Test</div>'))->toBe('minified:<div>Test</div>');
    });
});
