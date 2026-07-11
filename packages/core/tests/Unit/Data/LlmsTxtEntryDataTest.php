<?php

declare(strict_types=1);

use Capell\Core\Data\LlmsTxtEntryData;

it('renders llms text entries as markdown lines', function (): void {
    expect(new LlmsTxtEntryData('Docs', 'https://example.test/docs')->toMarkdownLine())
        ->toBe('- [Docs](https://example.test/docs)');

    expect(new LlmsTxtEntryData('Docs', 'https://example.test/docs', 'Developer documentation')->toMarkdownLine())
        ->toBe('- [Docs](https://example.test/docs): Developer documentation');
});
