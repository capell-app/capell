<?php

declare(strict_types=1);

use Capell\Frontend\Actions\RenderContentAction;

describe('RenderContentAction', function (): void {
    it('renders HTML content as array (single root)', function (): void {
        $html = '<div>Test</div>';
        $result = RenderContentAction::run($html, null, [], false, false, true);
        expect($result)->toBeArray();
        expect(count($result))->toBe(1);
        expect($result[0]['tag'])->toBe('div');
        expect($result[0]['text'])->toBe('Test');
        expect($result[0]['children'])->toBe([]);
    });

    it('renders HTML content as array (multiple roots)', function (): void {
        $html = '<div>One</div><span>Two</span>';
        $result = RenderContentAction::run($html, null, [], false, false, true);
        expect($result)->toBeArray();
        expect($result[0]['tag'])->toBe('div');
        expect($result[0]['text'])->toBe('One');
        expect($result[1]['tag'])->toBe('span');
        expect($result[1]['text'])->toBe('Two');
    });

    it('renders deeply nested HTML as array', function (): void {
        $html = '<div><span><b>Deep</b></span></div>';
        $result = RenderContentAction::run($html, null, [], false, false, true);
        expect($result)->toBeArray();
        expect($result[0]['tag'])->toBe('div');
        expect($result[0]['children'][0]['tag'])->toBe('span');
        expect($result[0]['children'][0]['children'][0]['tag'])->toBe('b');
        expect($result[0]['children'][0]['children'][0]['text'])->toBe('Deep');
    });

    it('handles only text nodes', function (): void {
        $html = 'Just text';
        $result = RenderContentAction::run($html, null, [], false, false, true);
        expect($result)->toBeArray();
        expect($result[0]['text'])->toBe('Just text');
    });

    it('handles empty string', function (): void {
        expect(RenderContentAction::run('', null, [], false, false, true))->toBeArray()->toBeEmpty();
    });

    it('handles html with attributes', function (): void {
        $html = '<div id="foo" class="bar">Baz</div>';
        $result = RenderContentAction::run($html, null, [], false, false, true);
        expect($result)->toBeArray();
        assert(is_array($result));
        $element = array_find($result, fn ($node): bool => isset($node['attributes']));

        expect($element)->not()->toBeNull();
        expect($element['attributes']['id'])->toBe('foo');
        expect($element['attributes']['class'])->toBe('bar');
    });

    it('converts complex HTML (h1, paragraphs, image) to array', function (): void {
        $html = <<<'HTML'
    <h1
        class="subheading font-heading font-medium text-gray-500 lg:text-md text-base block-heading"
    >
        Page title
    </h1>
    <p>Veniam fugiat deleniti cum sint voluptatum eos. Soluta corrupti molestiae quod unde reprehenderit voluptas. Ea nam qui sit sed nostrum. Maiores voluptatem totam rerum aut suscipit quidem consequatur. Autem labore laborum qui quia corrupti quidem.</p>
    <p>Aperiam dolor eveniet numquam eaque. Optio corporis earum corporis laboriosam dolore architecto tempore labore. Omnis consequatur est voluptas ut est placeat magni vitae. Et sed et quae natus officiis. Eos sint quia eveniet.</p>
    <img
        src="https://via.placeholder.com/640x480.png/001111?text=aut"
        alt="Corporis quia illum aspernatur eum molestiae voluptatem."
        class="mx-auto h-auto max-w-full"
    />
    HTML;

        $result = RenderContentAction::run($html, null, [], false, false, true);

        expect($result)->toBeArray()
            ->and($result[0]['tag'])->toBe('h1')
            ->and($result[0]['attributes']['class'])->toBe('subheading font-heading font-medium text-gray-500 lg:text-md text-base block-heading')
            ->and($result[0]['text'])->toBe('Page title')
            ->and($result[1]['tag'])->toBe('p')
            ->and($result[1]['text'])->toBe('Veniam fugiat deleniti cum sint voluptatum eos. Soluta corrupti molestiae quod unde reprehenderit voluptas. Ea nam qui sit sed nostrum. Maiores voluptatem totam rerum aut suscipit quidem consequatur. Autem labore laborum qui quia corrupti quidem.')
            ->and($result[2]['tag'])->toBe('p')
            ->and($result[2]['text'])->toBe('Aperiam dolor eveniet numquam eaque. Optio corporis earum corporis laboriosam dolore architecto tempore labore. Omnis consequatur est voluptas ut est placeat magni vitae. Et sed et quae natus officiis. Eos sint quia eveniet.')
            ->and($result[3]['tag'])->toBe('img')
            ->and($result[3]['attributes']['src'])->toBe('https://via.placeholder.com/640x480.png/001111?text=aut')
            ->and($result[3]['attributes']['alt'])->toBe('Corporis quia illum aspernatur eum molestiae voluptatem.')
            ->and($result[3]['attributes']['class'])->toBe('mx-auto h-auto max-w-full');
    });
});
