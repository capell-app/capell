<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Agent;

use Capell\Core\Contracts\Rendering\RenderHookExtensionInterface;
use Capell\Core\Data\Rendering\RenderHookContext;
use Capell\Frontend\Contracts\FrontendContextReader;

/** Exposes hydrated data through the hook shared by replaceable theme shells. */
final readonly class AgentSchemaRenderHook implements RenderHookExtensionInterface
{
    public function __construct(private FrontendContextReader $frontend) {}

    public function render(RenderHookContext $context): string
    {
        $data = $this->frontend->renderPayload()->publicPageRenderData?->extensionData('agent');
        if ($data === null) {
            return '';
        }

        $values = (array) $data;

        return view('capell::partials.agent-data', [
            'graph' => $values['graph'] ?? null,
            'manifest' => $values['manifest'] ?? null,
        ])->render();
    }
}
