<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Agent;

use Capell\Core\Data\Agent\AgentManifestAuditData;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Support\Agent\AgentToolDefinitionNormalizer;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** Shared, data-only audit for extension checks and Marketplace readiness. */
final class AuditAgentToolManifestAction
{
    use AsFake;
    use AsObject;

    /** @param array<string, mixed> $manifest */
    public function handle(array $manifest): AgentManifestAuditData
    {
        $interactive = false;
        $declarations = [];
        $errors = [];
        foreach (is_array($manifest['contributes'] ?? null) ? $manifest['contributes'] : [] as $contribution) {
            if (! is_array($contribution)) {
                continue;
            }

            $metadata = is_array($contribution['metadata'] ?? null) ? $contribution['metadata'] : [];
            $surface = $contribution['surface'] ?? $metadata['surface'] ?? null;
            $public = in_array($surface, ['frontend', 'public'], true)
                || ($contribution['public'] ?? $metadata['public'] ?? false) === true;
            if ($public && in_array($contribution['type'] ?? null, [
                ExtensionContributionType::Route->value,
                ExtensionContributionType::FrontendComponent->value,
                ExtensionContributionType::ContentWidget->value,
            ], true)) {
                $interactive = true;
            }

            if (($contribution['type'] ?? null) !== ExtensionContributionType::AgentCapability->value) {
                continue;
            }

            if (($contribution['context'] ?? $metadata['context'] ?? null) !== 'public' && ! $public) {
                continue;
            }

            if ($surface === 'admin') {
                continue;
            }

            if (($contribution['context'] ?? $metadata['context'] ?? null) === 'admin') {
                continue;
            }

            $definition = array_intersect_key($contribution + $metadata, array_flip([
                'name', 'description', 'descriptionKey', 'inputSchema', 'outputSchema', 'effect', 'binding',
            ]));
            $definition['name'] ??= $contribution['key'] ?? null;
            $declarations[] = $definition;
        }

        $declared = $manifest['agent_tools'] ?? null;
        $waiverReason = null;
        if ($declared === 'none' || is_array($declared) && ($declared['none'] ?? false) === true) {
            $reason = is_array($declared) ? ($declared['reason'] ?? null) : ($manifest['agent_tools_reason'] ?? null);
            if (is_string($reason) && trim($reason) !== '') {
                $waiverReason = trim($reason);
            } else {
                $errors[] = 'An agent tool waiver requires a reason.';
            }
        } elseif (is_array($declared)) {
            $tools = array_is_list($declared) ? $declared : ($declared['tools'] ?? []);
            if (is_array($tools) && array_is_list($tools)) {
                array_push($declarations, ...$tools);
            } else {
                $errors[] = 'Agent tool declarations must be a list.';
            }
        } elseif ($declared !== null) {
            $errors[] = 'Agent tool declarations are invalid.';
        }

        $validated = 0;
        $names = [];
        foreach ($declarations as $definition) {
            if (! is_array($definition)) {
                $errors[] = 'Agent tool declaration must be an object.';

                continue;
            }

            // Class-only declarations require execution in the installed package
            // audit. A catalogue snapshot alone cannot attest their tool contract.
            if (! array_key_exists('binding', $definition)) {
                continue;
            }

            try {
                $tool = resolve(AgentToolDefinitionNormalizer::class)->normalize($definition);
                if (isset($names[$tool->name])) {
                    $errors[] = 'Agent tool names must be unique.';

                    continue;
                }

                $names[$tool->name] = true;
                $validated++;
            } catch (InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        return new AgentManifestAuditData($interactive, count($declarations), $validated, $waiverReason, $errors);
    }
}
