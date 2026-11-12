<?php

declare(strict_types=1);

namespace Capell\Core\Support\Privacy;

use Capell\Core\Contracts\Privacy\ConsentDecisionProvider;
use Capell\Core\Enums\ConsentCategory;
use Capell\Core\Enums\ConsentDecision;
use Illuminate\Http\Request;
use Override;

/**
 * The decisions Core makes when no consent manager is installed. It records nothing and
 * sets no cookie: each category resolves to its configured default, and a Global Privacy
 * Control (Sec-GPC: 1) or Do Not Track (DNT: 1) signal refuses the opt-out categories.
 */
final readonly class DefaultConsentDecisionProvider implements ConsentDecisionProvider
{
    public function __construct(private ConsentSettings $settings) {}

    /** Whether the request carries a Global Privacy Control or Do Not Track opt-out. */
    public static function hasOptOutSignal(Request $request): bool
    {
        if ($request->header('Sec-GPC') === '1') {
            return true;
        }

        return $request->header('DNT') === '1';
    }

    #[Override]
    public function categories(): array
    {
        return ConsentCategory::values();
    }

    #[Override]
    public function purposes(): array
    {
        return $this->settings->purposes();
    }

    #[Override]
    public function decide(string $purposeOrCategory, Request $request): ConsentDecision
    {
        $category = ConsentCategory::tryFrom($purposeOrCategory)
            ?? ConsentCategory::tryFrom($this->purposes()[$purposeOrCategory] ?? '');

        if (! $category instanceof ConsentCategory) {
            return ConsentDecision::Undecided;
        }

        if ($category === ConsentCategory::Essential) {
            return ConsentDecision::Granted;
        }

        if (self::hasOptOutSignal($request) && in_array($category, $this->settings->optOutSignalCategories(), true)) {
            return ConsentDecision::Denied;
        }

        return $this->settings->defaultDecision($category);
    }
}
