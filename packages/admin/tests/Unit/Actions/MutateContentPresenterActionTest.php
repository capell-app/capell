<?php

declare(strict_types=1);

use Capell\Admin\Actions\MutateContentPresenterAction;
use Capell\Core\Enums\ContentStructure;

beforeEach(function (): void {
    Mockery::close();
    Mockery::getConfiguration()->allowMockingNonExistentMethods(true);
    Mockery::resetContainer();
});

afterEach(function (): void {
    Mockery::close();
});

it('returns HTML string as-is for Html structure', function (): void {
    $content = '<p>Hello</p>';
    $result = MutateContentPresenterAction::run($content, ContentStructure::Html);
    expect($result)->toBe('<p>Hello</p>');
});

it('returns null for invalid input for Html structure', function (): void {
    $result = MutateContentPresenterAction::run(123, ContentStructure::Html);
    expect($result)->toBeNull();
});

it('wraps string as block for Blocks structure', function (): void {
    $content = 'Block content';
    $result = MutateContentPresenterAction::run($content, ContentStructure::Blocks);
    /** @var list<array{data: array{content: mixed}}> $result */
    expect($result)->toBeArray()
        ->and($result[0]['data']['content'])->toBe('Block content');
});

it('returns array as-is for Blocks structure', function (): void {
    $blocks = [
        ['type' => 'content', 'data' => ['content' => 'Keep me']],
    ];
    $result = MutateContentPresenterAction::run($blocks, ContentStructure::Blocks);
    expect($result)->toBe($blocks);
});

it('wraps array as block if force is true and missing keys', function (): void {
    $input = ['foo' => 'bar'];
    $result = MutateContentPresenterAction::run($input, ContentStructure::Blocks, true);
    /** @var list<array{data: array{content: mixed}}> $result */
    expect($result[0]['data']['content'])->toBe($input);
});

it('returns array as-is for Blocks structure if force is false and missing keys', function (): void {
    $input = ['foo' => 'bar'];
    $result = MutateContentPresenterAction::run($input, ContentStructure::Blocks, false);
    expect($result)->toBe($input);
});

it('defaults to Html structure if not provided', function (): void {
    $content = '<em>Default</em>';
    $result = MutateContentPresenterAction::run($content);
    expect($result)->toBe('<em>Default</em>');
});

it('does not wrap content that is already a collection of blocks when forced', function (array $input): void {
    expect(MutateContentPresenterAction::run($input, ContentStructure::Blocks, true))->toBe($input);
})->with([
    'list of blocks' => [[['type' => 'hero', 'data' => ['title' => 'A']], ['type' => 'content', 'data' => []]]],
    'uuid keyed blocks' => [['5c3f1a52-6f0d-4a3e-9a2c-0f2a8f4f6b10' => ['type' => 'hero', 'data' => ['title' => 'A']]]],
]);
