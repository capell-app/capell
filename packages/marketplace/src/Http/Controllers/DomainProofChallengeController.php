<?php

declare(strict_types=1);

namespace Capell\Marketplace\Http\Controllers;

use Capell\Marketplace\Support\DomainProof\DomainProofChallengeStore;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Answers capell.app's fetch of a proof token with the token itself, and
 * nothing else. Unknown, expired or other-host tokens get a plain 404.
 */
final class DomainProofChallengeController
{
    public function __invoke(Request $request, string $token, DomainProofChallengeStore $store): Response
    {
        // Only on the host capell.app sent the token for, so another site sharing this server cannot borrow it.
        abort_unless($store->holds($token, $request->getHost()), 404);

        return response($token, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
