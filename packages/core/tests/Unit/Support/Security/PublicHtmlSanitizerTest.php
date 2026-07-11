<?php

declare(strict_types=1);

use Capell\Core\Support\Security\PublicHtmlSanitizer;

it('sanitizes public html with the shared allow list', function (): void {
    $html = '<p onclick="alert(1)"><a href="javascript:alert(2)">Book</a></p><script>alert(3)</script><span class="eyebrow">Safe</span>';

    expect((new PublicHtmlSanitizer)->sanitize($html))
        ->toBe('<p><a>Book</a></p><span>Safe</span>');
});

it('sanitizes nested public payload values and removes blocked authoring keys', function (): void {
    $payload = [
        'copy' => '<div onclick="alert(1)">Public</div>',
        'admin_url' => '/admin/pages/1?signature=secret',
        'nested' => [
            'summary' => '<strong data-capell-authoring="true">Safe</strong>',
            'token' => 'secret-token',
        ],
    ];

    expect((new PublicHtmlSanitizer)->sanitizePublicValue($payload))
        ->toBe([
            'copy' => '<div>Public</div>',
            'nested' => [
                'summary' => '<strong>Safe</strong>',
            ],
        ]);
});
