<?php

declare(strict_types=1);

return [
    'auto_redirects' => [
        'enabled' => env('CAPELL_REDIRECTS_AUTO_ENABLED', true),
        'status_code' => 301,
    ],
];
