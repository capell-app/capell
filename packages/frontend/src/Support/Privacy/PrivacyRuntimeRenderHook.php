<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Privacy;

use Capell\Core\Contracts\Privacy\ConsentDecisionProvider;
use Capell\Core\Enums\ConsentCategory;
use Capell\Core\Support\Privacy\ConsentSettings;
use Capell\Frontend\Contracts\RenderHookExtensionInterface;
use Capell\Frontend\Data\RenderHookContext;
use Override;

/**
 * Loads the default window.CapellPrivacy runtime in the head, before theme and widget
 * scripts ask for consent. The fragment is cached with the page and shared by every
 * visitor, so it carries only the site's configuration and a script URL: no request
 * state, no CSP nonce and no cookie.
 */
final readonly class PrivacyRuntimeRenderHook implements RenderHookExtensionInterface
{
    public function __construct(
        private ConsentDecisionProvider $provider,
        private ConsentSettings $settings,
        private PrivacyRuntimeScript $script,
    ) {}

    #[Override]
    public function render(RenderHookContext $context): string
    {
        $config = json_encode($this->config(), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);

        return '<script type="application/json" id="capell-privacy-runtime-config">' . $config . '</script>'
            . '<script src="' . e($this->script->url()) . '"></script>';
    }

    /**
     * @return array{categories: list<string>, purposes: array<string, string>|object, defaults: array<string, bool|null>, optOutCategories: list<string>, framePlaceholder: array{message: string, manage: string}}
     */
    private function config(): array
    {
        $categories = array_values(array_filter(
            $this->provider->categories(),
            static fn (string $category): bool => ConsentCategory::tryFrom($category) instanceof ConsentCategory,
        ));
        $defaults = [];

        foreach ($categories as $category) {
            $defaults[$category] = $this->settings->defaultDecision(ConsentCategory::from($category))->allows();
        }

        $purposes = $this->provider->purposes();

        return [
            'categories' => $categories,
            // An empty map stays a JSON object, so the runtime never reads a list as purposes.
            'purposes' => $purposes === [] ? (object) [] : $purposes,
            'defaults' => $defaults,
            'optOutCategories' => array_map(static fn (ConsentCategory $category): string => $category->value, $this->settings->optOutSignalCategories()),
            // Shown in place of a frame that waits for consent, in the page's language.
            'framePlaceholder' => [
                'message' => (string) __('capell-frontend::messages.consent_frame_waiting'),
                'manage' => (string) __('capell-frontend::messages.consent_frame_manage'),
            ],
        ];
    }
}
