<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Redirects;

use Capell\Core\Actions\Redirects\ValidateRedirectAction;
use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\SiteAccess;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Collects the non-blocking advice for a redirect being written, such as a
 * target that is itself a redirect. Advice is only offered for a rule that
 * would otherwise save: blocking errors are reported by validation, and advice
 * is never produced for a site the editor cannot access.
 *
 * @method static list<string> run(?string $sourceUrl, ?string $targetUrl, mixed $siteId, mixed $languageId, ?int $excludeId = null)
 */
final class BuildRedirectAdviceAction
{
    use AsFake;
    use AsObject;

    /**
     * @return list<string>
     */
    public function handle(
        ?string $sourceUrl,
        ?string $targetUrl,
        mixed $siteId,
        mixed $languageId,
        ?int $excludeId = null,
    ): array {
        if (blank($sourceUrl) || blank($targetUrl) || ! is_numeric($siteId) || ! is_numeric($languageId)) {
            return [];
        }

        $siteId = (int) $siteId;

        if (! SiteAccess::current()->query(Site::class)->whereKey($siteId)->exists()) {
            return [];
        }

        $result = ValidateRedirectAction::run(
            sourceUrl: $sourceUrl,
            targetUrl: $targetUrl,
            siteId: $siteId,
            languageId: (int) $languageId,
            excludeId: $excludeId,
            validateDuplicateSource: false,
        );

        return $result['errors'] === [] ? $result['warnings'] : [];
    }
}
