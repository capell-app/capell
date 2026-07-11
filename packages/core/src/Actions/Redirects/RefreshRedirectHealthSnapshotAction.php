<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Redirects;

use Capell\Core\Models\PageUrl;
use Capell\Core\Models\RedirectHealthSnapshot;
use Lorisleiva\Actions\Concerns\AsAction;

class RefreshRedirectHealthSnapshotAction
{
    use AsAction;

    public function handle(PageUrl $redirect): RedirectHealthSnapshot
    {
        $errors = [];
        $warnings = [];

        if ($redirect->target_url !== null && $redirect->target_url !== '') {
            $result = ValidateRedirectAction::run(
                sourceUrl: $redirect->url,
                targetUrl: $redirect->target_url,
                siteId: $redirect->site_id,
                languageId: $redirect->language_id,
                excludeId: $redirect->id,
                statusCode: $redirect->status_code->value,
                validateDuplicateSource: false,
            );

            $errors = $result['errors'];
            $warnings = $result['warnings'];
        }

        return RedirectHealthSnapshot::query()->updateOrCreate(
            ['page_url_id' => $redirect->id],
            [
                'source_url' => $redirect->url,
                'target_url' => $redirect->target_url,
                'has_chain' => $this->hasChainWarning($warnings),
                'has_loop' => in_array(__('capell::message.redirect_loop_detected'), $errors, true),
                'warning_count' => count($warnings),
                'error_count' => count($errors),
                'computed_at' => now(),
            ],
        );
    }

    /**
     * @param  list<string>  $warnings
     */
    private function hasChainWarning(array $warnings): bool
    {
        $chainWarningMessage = __('capell::message.redirect_chain_detected', [
            'final_target' => $this->chainTargetPlaceholder(),
        ]);
        $chainWarningParts = explode($this->chainTargetPlaceholder(), $chainWarningMessage, 2);
        $chainWarningPrefix = $chainWarningParts[0];
        $chainWarningSuffix = $chainWarningParts[1] ?? '';

        return array_any($warnings, fn (string $warning): bool => str_starts_with($warning, $chainWarningPrefix) && str_ends_with($warning, $chainWarningSuffix));
    }

    /** @return non-empty-string */
    private function chainTargetPlaceholder(): string
    {
        return '__CAPELL_REDIRECT_CHAIN_TARGET__';
    }
}
