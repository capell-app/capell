<?php

declare(strict_types=1);

use Capell\Core\Actions\Content\ExtractTextContentAction;

it('extracts plain text from HTML strings', function (): void {
    $input = '<p>Hello <strong>World</strong></p>';

    $output = ExtractTextContentAction::run($input);

    expect($output)->toBe('Hello World');
});

it('extracts text from JSON content field', function (): void {
    $input = json_encode(['content' => ['Hello', 'World']]);

    $output = ExtractTextContentAction::run($input);

    expect($output)->toBe('Hello World');
});

it('extracts text from nested array content', function (): void {
    $input = ['content' => ['Hello', ['World', 'from'], 'Capell']];

    $output = ExtractTextContentAction::run($input);

    expect($output)->toBe('Hello World from Capell');
});

it('strips HTML from nested array content', function (): void {
    $input = ['content' => ['content', '<p>Le Lorem</p>', ['<strong>Ipsum</strong>']]];

    $output = ExtractTextContentAction::run($input);

    expect($output)->toBe('content Le Lorem Ipsum');
});

it('applies word-safe limit without splitting words', function (): void {
    $input = 'Hello amazing world of content';

    $output = ExtractTextContentAction::run($input, 12);

    // 12 chars would cut after 'Hello amazing', but we trim to last whitespace
    expect($output)->toBe('Hello');

    $output2 = ExtractTextContentAction::run($input, 20);
    expect($output2)->toBe('Hello amazing world');
});

it('returns hard slice when no whitespace exists', function (): void {
    $input = 'Supercalifragilisticexpialidocious';

    $output = ExtractTextContentAction::run($input, 10);

    expect($output)->toBe('Supercalif');
});

it('handles multibyte characters safely', function (): void {
    $input = 'Привет мир контент';

    $output = ExtractTextContentAction::run($input, 12);

    // 12-char limit, keep whole words when possible
    expect(mb_strlen((string) $output))->toBeLessThanOrEqual(12);
    expect($output)->not()->toEndWith(' ');
});
