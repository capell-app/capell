<?php

declare(strict_types=1);

namespace Capell\Marketplace\Http\Controllers;

use Capell\Marketplace\Support\DomainProof\DomainProofChallengeStore;
use Illuminate\Http\Response;

/**
 * Answers capell.app's fetch of a proof token with the token itself, and
 * nothing else. Unknown or expired tokens get a plain 404.
 */
final class DomainProofChallengeController
{
    public function __invoke(string $token, DomainProofChallengeStore $store): Response
    {
        abort_unless($store->holds($token), 404);

        return response($token, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
