<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/tests/Support/DomQuery.php';

it('renders readable escaped fallback validation results in light and dark mode', function (): void {
    $results = ['unexpected' => '<script>alert("unsafe")</script>'];
    $record = (object) ['validation_results' => $results];
    $html = view('capell-admin::components.exchanger.import-session-validation', [
        'getRecord' => static fn (): object => $record,
    ])->render();
    $xpath = domXPath($html);
    $fallback = domElement($xpath, '//pre[code]');

    expect(json_decode((string) $fallback->textContent, true, flags: JSON_THROW_ON_ERROR))->toBe($results)
        ->and(domCount($xpath, '//script'))->toBe(0);
    $classes = preg_split('/\s+/', (string) $fallback->getAttribute('class'));
    expect($classes)->toContain('bg-gray-50', 'text-gray-900', 'dark:bg-white/5', 'dark:text-gray-100');
});
