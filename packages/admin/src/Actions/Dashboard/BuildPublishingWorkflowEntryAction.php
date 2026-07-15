<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Dashboard;

use Capell\Admin\Actions\Publishing\BuildPublishReadinessAction;
use Capell\Admin\Data\Dashboard\PublishingWorkflowEntryData;
use Capell\Admin\Data\Publishing\PublishReadinessData;
use Capell\Core\Contracts\Extensions\ContributesWorkflowAttention;
use Capell\Core\Data\Manifest\ExtensionContributionData;
use Capell\Core\Data\Workflow\WorkflowAttentionItemData;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Contracts\Publishable;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final class BuildPublishingWorkflowEntryAction
{
    use AsAction;

    public function handle(
        ?Authenticatable $user = null,
        (Model&Publishable)|null $record = null,
    ): ?PublishingWorkflowEntryData {
        $readiness = $record !== null
            ? BuildPublishReadinessAction::run($record)
            : null;

        foreach (resolve(CapellPackageRegistry::class)->all() as $manifest) {
            $entry = $this->entryForManifest($manifest, $user, $readiness);

            if ($entry instanceof PublishingWorkflowEntryData) {
                return $entry;
            }
        }

        return null;
    }

    private function entryForManifest(
        CapellManifestData $manifest,
        ?Authenticatable $user,
        ?PublishReadinessData $readiness,
    ): ?PublishingWorkflowEntryData {
        if (! CapellCore::isPackageInstalled($manifest->name)) {
            return null;
        }

        foreach ($manifest->contributes as $contribution) {
            if ($contribution->type !== ExtensionContributionType::WorkflowAttention) {
                continue;
            }

            $attentionItems = $this->workflowAttentionItems($contribution, $user);
            $firstAttentionItem = $attentionItems[0] ?? null;
            $permission = $firstAttentionItem instanceof WorkflowAttentionItemData
                ? $firstAttentionItem->permission
                : null;
            $permission ??= $this->metadataString($contribution, 'permission');

            if ($permission !== null && $user?->can($permission) !== true) {
                continue;
            }

            $url = ($firstAttentionItem instanceof WorkflowAttentionItemData ? $firstAttentionItem->url : null)
                ?? $this->routeUrl($firstAttentionItem instanceof WorkflowAttentionItemData ? $firstAttentionItem->routeName : null)
                ?? $this->managementUrl($contribution);

            if ($url === null) {
                continue;
            }

            return new PublishingWorkflowEntryData(
                label: $this->metadataLabel($contribution, 'labelKey', 'label', $manifest->displayName),
                description: $this->metadataLabel(
                    $contribution,
                    'descriptionKey',
                    'description',
                    (string) __('capell-admin::dashboard.publishing_workflow_description'),
                ),
                url: $url,
                actionLabel: $this->metadataLabel(
                    $contribution,
                    'actionLabelKey',
                    'actionLabel',
                    (string) __('capell-admin::dashboard.publishing_workflow_action'),
                ),
                count: $this->attentionCount($attentionItems, $contribution, $readiness),
            );
        }

        return null;
    }

    /**
     * @return list<WorkflowAttentionItemData>
     */
    private function workflowAttentionItems(ExtensionContributionData $contribution, ?Authenticatable $user): array
    {
        if (! is_string($contribution->class) || ! class_exists($contribution->class)) {
            return [];
        }

        try {
            $resolvedContribution = resolve($contribution->class);

            if (! $resolvedContribution instanceof ContributesWorkflowAttention) {
                return [];
            }

            /** @var list<WorkflowAttentionItemData> $attentionItems */
            $attentionItems = $resolvedContribution->attentionItems($user);

            return $attentionItems;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  list<WorkflowAttentionItemData>  $attentionItems
     */
    private function attentionCount(
        array $attentionItems,
        ExtensionContributionData $contribution,
        ?PublishReadinessData $readiness,
    ): int {
        if ($attentionItems !== []) {
            return array_sum(array_map(
                static fn (WorkflowAttentionItemData $attentionItem): int => $attentionItem->count ?? 1,
                $attentionItems,
            ));
        }

        $count = $contribution->metadata['count'] ?? null;

        if (! is_numeric($count) && $readiness instanceof PublishReadinessData) {
            return count($readiness->blockingCheckIds);
        }

        return is_numeric($count) ? (int) $count : 0;
    }

    private function managementUrl(ExtensionContributionData $contribution): ?string
    {
        $pageClass = $this->metadataString($contribution, 'pageClass');

        if ($pageClass !== null && class_exists($pageClass) && method_exists($pageClass, 'getUrl')) {
            if (method_exists($pageClass, 'canAccess') && $pageClass::canAccess() !== true) {
                return null;
            }

            /** @var callable(): string $getUrl */
            $getUrl = [$pageClass, 'getUrl'];

            return $getUrl();
        }

        $url = $this->metadataString($contribution, 'managementUrl');

        if ($url !== null) {
            return $url;
        }

        $route = $this->metadataString($contribution, 'managementRoute');

        if ($route === null || ! Route::has($route)) {
            return null;
        }

        try {
            return route($route);
        } catch (Throwable) {
            return null;
        }
    }

    private function routeUrl(?string $route): ?string
    {
        if ($route === null || ! Route::has($route)) {
            return null;
        }

        try {
            return route($route);
        } catch (Throwable) {
            return null;
        }
    }

    private function metadataLabel(
        ExtensionContributionData $contribution,
        string $translationKey,
        string $literalKey,
        string $fallback,
    ): string {
        $translation = $this->metadataString($contribution, $translationKey);

        if ($translation !== null) {
            return (string) __($translation);
        }

        return $this->metadataString($contribution, $literalKey) ?? $fallback;
    }

    private function metadataString(ExtensionContributionData $contribution, string $key): ?string
    {
        $value = $contribution->metadata[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
