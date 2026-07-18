<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Redirects;

use Capell\Core\Enums\RedirectStatusCodeEnum;
use Capell\Core\Models\PageUrl;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static array{errors: list<string>, warnings: list<string>} run(string $sourceUrl, string $targetUrl, int $siteId, int $languageId, ?int $excludeId = null, ?int $statusCode = null, bool $validateDuplicateSource = true)
 */
final class ValidateRedirectAction
{
    use AsFake;
    use AsObject;

    /**
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function handle(
        string $sourceUrl,
        string $targetUrl,
        int $siteId,
        int $languageId,
        ?int $excludeId = null,
        ?int $statusCode = null,
        bool $validateDuplicateSource = true,
    ): array {
        $errors = [];
        $warnings = [];

        $this->validateSourceUrl($sourceUrl, $errors);
        $this->validateTargetUrl($targetUrl, $sourceUrl, $errors);
        $this->validateStatusCode($statusCode, $errors);
        if ($validateDuplicateSource) {
            $this->validateDuplicateSource($sourceUrl, $siteId, $languageId, $excludeId, $errors);
        }

        $this->detectLoop($sourceUrl, $targetUrl, $siteId, $languageId, $errors);
        $this->detectChain($targetUrl, $siteId, $languageId, $warnings);
        $this->detectAutoRedirectConflict($sourceUrl, $siteId, $languageId, $excludeId, $warnings);

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateSourceUrl(string $sourceUrl, array &$errors): void
    {
        if ($sourceUrl === '') {
            $errors[] = (string) __('capell::message.redirect_source_empty');

            return;
        }

        if (! str_starts_with($sourceUrl, '/')) {
            $errors[] = (string) __('capell::message.redirect_source_must_start_with_slash');
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateTargetUrl(string $targetUrl, string $sourceUrl, array &$errors): void
    {
        if ($targetUrl === '') {
            $errors[] = (string) __('capell::message.redirect_target_empty');

            return;
        }

        if (! $this->isSafeTargetUrl($targetUrl)) {
            $errors[] = (string) __('capell::message.redirect_target_invalid');

            return;
        }

        if ($sourceUrl === $targetUrl) {
            $errors[] = (string) __('capell::message.redirect_self_redirect');
        }
    }

    private function isSafeTargetUrl(string $targetUrl): bool
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $targetUrl) === 1) {
            return false;
        }

        if (str_starts_with($targetUrl, '//')) {
            return false;
        }

        if (str_starts_with($targetUrl, '/')) {
            return true;
        }

        $scheme = parse_url($targetUrl, PHP_URL_SCHEME);

        if (! is_string($scheme)) {
            return false;
        }

        return in_array(mb_strtolower($scheme), ['http', 'https'], true)
            && filter_var($targetUrl, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateStatusCode(?int $statusCode, array &$errors): void
    {
        if ($statusCode === null) {
            return;
        }

        if (RedirectStatusCodeEnum::tryFrom($statusCode) === null) {
            $errors[] = (string) __('capell::message.redirect_invalid_status_code');
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateDuplicateSource(
        string $sourceUrl,
        int $siteId,
        int $languageId,
        ?int $excludeId,
        array &$errors,
    ): void {
        $exists = PageUrl::query()
            ->where('url', $sourceUrl)
            ->where('site_id', $siteId)
            ->where('language_id', $languageId)
            ->enabled()
            ->when($excludeId, fn (Builder $query) => $query->where('id', '!=', $excludeId))
            ->exists();

        if ($exists) {
            $errors[] = (string) __('capell::message.redirect_duplicate_source');
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function detectLoop(
        string $sourceUrl,
        string $targetUrl,
        int $siteId,
        int $languageId,
        array &$errors,
    ): void {
        $visited = [$sourceUrl];
        $current = $targetUrl;

        for ($i = 0; $i < $this->maxChainDepth(); $i++) {
            if (in_array($current, $visited, true)) {
                $errors[] = (string) __('capell::message.redirect_loop_detected');

                return;
            }

            $next = PageUrl::query()
                ->where('url', $current)
                ->where('site_id', $siteId)
                ->where('language_id', $languageId)
                ->activeRedirects()
                ->value('target_url');

            if ($next === null) {
                return;
            }

            $visited[] = $current;
            $current = $next;
        }
    }

    /**
     * @param  list<string>  $warnings
     */
    private function detectChain(
        string $targetUrl,
        int $siteId,
        int $languageId,
        array &$warnings,
    ): void {
        $chainTarget = PageUrl::query()
            ->where('url', $targetUrl)
            ->where('site_id', $siteId)
            ->where('language_id', $languageId)
            ->activeRedirects()
            ->value('target_url');

        if ($chainTarget !== null) {
            $warnings[] = (string) __('capell::message.redirect_chain_detected', [
                'final_target' => $chainTarget,
            ]);
        }
    }

    /**
     * @param  list<string>  $warnings
     */
    private function detectAutoRedirectConflict(
        string $sourceUrl,
        int $siteId,
        int $languageId,
        ?int $excludeId,
        array &$warnings,
    ): void {
        $exists = PageUrl::query()
            ->where('url', $sourceUrl)
            ->where('site_id', $siteId)
            ->where('language_id', $languageId)
            ->redirects()
            ->where('is_manual', false)
            ->when($excludeId, fn (Builder $query) => $query->where('id', '!=', $excludeId))
            ->exists();

        if ($exists) {
            $warnings[] = (string) __('capell::message.redirect_auto_conflict');
        }
    }

    private function maxChainDepth(): int
    {
        return 10;
    }
}
