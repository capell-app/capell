<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Exceptions\PublicRenderContractViolationException;
use Capell\Frontend\Support\Security\PublicHtmlSafetyInspector;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

/**
 * @method static void run(Response $response)
 */
class AssertPublicHtmlContainsNoAuthoringSurfaceAction
{
    use AsObject;

    public const string SAFE_INSPECTION_HASH_ATTRIBUTE = 'capell.frontend.public_html_safety.inspected_hash';

    public const string SAFE_INSPECTION_PASSED_ATTRIBUTE = 'capell.frontend.public_html_safety.passed';

    public function handle(Response $response): void
    {
        $content = $response->getContent();

        if (! is_string($content) || $content === '') {
            return;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');

        if ($contentType !== '' && ! str_contains(strtolower($contentType), 'text/html')) {
            return;
        }

        $detection = resolve(PublicHtmlSafetyInspector::class)->detectAuthoringSurface($content);

        if ($detection === null) {
            return;
        }

        Log::warning('capell-frontend: public HTML contains an authoring surface', [
            'category' => $detection->category,
            'matched' => $detection->matched,
        ]);

        throw new PublicRenderContractViolationException(
            reason: $detection->reason,
            matched: $detection->matched,
            category: $detection->category,
        );
    }
}
