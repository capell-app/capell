<?php

declare(strict_types=1);

use Capell\Core\Support\Composer\InstallComposerFailure;

it('gives an actionable explanation without inventing an entitlement denial', function (string $diagnostic, string $expected): void {
    expect(InstallComposerFailure::explanation($diagnostic))->toContain($expected);
})->with([
    'credentials' => ['The URL required authentication (HTTP 401)', 'could not authenticate'],
    'access denied has no purchase proof' => ['HTTP 403 Forbidden', 'does not prove that a purchase is required'],
    'explicit licence denial' => ['The licence does not cover this extension', 'download licence was rejected'],
    'versions' => ['Your requirements could not be resolved to an installable set of packages', 'compatible set of package versions'],
    'version number is not an HTTP error' => ['Package vendor/example 1.0.403 conflicts with another requirement', 'compatible set of package versions'],
    'package absent' => ['Package vendor/example was not found.', 'name, repository configuration'],
    'repository offline' => ['curl error 6: Could not resolve host', 'network connection'],
    'other failure' => ['Unexpected process failure', 'diagnostic output'],
]);
