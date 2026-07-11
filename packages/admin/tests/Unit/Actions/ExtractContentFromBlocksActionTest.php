<?php

declare(strict_types=1);

use Capell\Admin\Actions\ExtractContentFromBlocksAction;

it('extracts content from blocks', function (): void {
    $blocks = [
        ['type' => 'content', 'content' => 'Hello'],
        ['type' => 'content', 'content' => 'World'],
    ];

    $content = ExtractContentFromBlocksAction::run($blocks);

    expect($content)->toBe("Hello\nWorld");
});

it('extracts content from the canonical nested data shape', function (): void {
    // Shape emitted by MutateContentPresenterAction / the content block factory.
    $blocks = [
        ['type' => 'content', 'data' => ['content' => '<p>Hello</p>']],
        ['type' => 'content', 'data' => ['content' => '<p>World</p>']],
    ];

    $content = ExtractContentFromBlocksAction::run($blocks);

    expect($content)->toBe("<p>Hello</p>\n<p>World</p>");
});

it('ignores a non-content block that carries a nested data payload', function (): void {
    $blocks = [['type' => 'image', 'data' => ['content' => 'not extracted']]];

    $content = ExtractContentFromBlocksAction::run($blocks);

    expect($content)->toBe('');
});

it('ignores invalid block shape', function (): void {
    $blocks = [['nope' => 'x']];

    $content = ExtractContentFromBlocksAction::run($blocks);

    expect($content)->toBe('');
});

it('extracts content from an array of strings', function (): void {
    $blocks = ['Alpha', 'Beta', 'Gamma'];

    $content = ExtractContentFromBlocksAction::run($blocks);

    expect($content)->toBe("Alpha\nBeta\nGamma");
});

it('extracts content from an array of Block-like objects', function (): void {
    $blocks = [
        new class
        {
            public string $type = 'content';

            public string $content = 'One';
        },
        new class implements JsonSerializable
        {
            /** @return array{type: string, content: string} */
            public function jsonSerialize(): array
            {
                return ['type' => 'content', 'content' => 'Two'];
            }
        },
        new class
        {
            public function getType(): string
            {
                return 'content';
            }

            public function getContent(): string
            {
                return 'Three';
            }
        },
        new class
        {
            /** @return array{type: string, content: string} */
            public function toArray(): array
            {
                return ['type' => 'content', 'content' => 'Four'];
            }
        },
    ];

    $content = ExtractContentFromBlocksAction::run($blocks);

    expect($content)->toBe("One\nTwo\nThree\nFour");
});
