<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Serializers;

use Capell\Core\EventSourcing\Contracts\EventSourcedStateSerializer;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Publishing\PublicationDateGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Captures and restores the full owned state of a Page aggregate: page
 * attributes + per-translation content/title/meta + pageUrls + tree position.
 *
 * The translation content path deliberately mirrors the legacy snapshot system
 * (capture getRawOriginal('content'), restore via forceFill) so an event-sourced
 * rollback reproduces byte-identical content and Phase 6 can retire snapshots.
 *
 * Restore runs event-silent (Model::withoutEvents) so it never re-triggers the
 * recording bridge; side-effects (cache/redirect/beacon) are driven by the
 * reactor reacting to the PageRolledBack event instead.
 */
final class PageStateSerializer implements EventSourcedStateSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function capture(Model $model): array
    {
        $page = $this->asPage($model);
        // Force a fresh load (not loadMissing): capture runs after a write, so
        // the database is the source of truth — a stale or empty in-memory
        // relation must never be snapshotted.
        $page->load(['translations', 'pageUrls']);

        return [
            'attributes' => [
                'name' => $page->name,
                'blueprint_id' => $page->blueprint_id,
                'layout_id' => $page->layout_id,
                'site_id' => $page->site_id,
                'parent_id' => $page->parent_id,
                'order' => $page->order,
                'meta' => $page->meta,
                'admin' => $page->admin,
                'visible_from' => $page->visible_from?->toIso8601String(),
                'visible_until' => $page->visible_until?->toIso8601String(),
                // Per-page HTML↔Blocks authoring mode. Captured so a rollback
                // across a mode switch restores the mode too, not just content.
                'content_structure_override' => $page->getAttributeFromArray('content_structure_override'),
            ],
            'translations' => $page->translations
                ->map(static fn (Translation $translation): array => [
                    'language_id' => $translation->language_id,
                    'title' => $translation->title,
                    'content' => $translation->getRawOriginal('content'),
                    'meta' => $translation->meta,
                ])
                ->values()
                ->all(),
            'pageUrls' => $page->pageUrls
                ->map(static fn (PageUrl $pageUrl): array => [
                    'language_id' => $pageUrl->language_id,
                    'site_id' => $pageUrl->site_id,
                    'url' => $pageUrl->url,
                    'target_url' => $pageUrl->target_url,
                    'status_code' => $pageUrl->getRawOriginal('status_code'),
                    'type' => $pageUrl->getRawOriginal('type'),
                    'is_manual' => (bool) $pageUrl->is_manual,
                    'status' => (bool) $pageUrl->status,
                    'notes' => $pageUrl->notes,
                    // Analytics columns: captured so a url recreated from
                    // scratch on rollback can restore its historical counts
                    // rather than resetting to zero. Existing rows keep their
                    // live counts untouched on restore (see restorePageUrls).
                    'hit_count' => (int) $pageUrl->hit_count,
                    'last_hit_at' => $pageUrl->last_hit_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function restore(Model $model, array $state): void
    {
        $page = $this->asPage($model);

        PublicationDateGuard::allow(function () use ($page, $state): void {
            DB::transaction(function () use ($page, $state): void {
                Model::withoutEvents(function () use ($page, $state): void {
                    $this->restoreAttributes($page, $state['attributes'] ?? []);
                    $this->restoreTranslations($page, $state['translations'] ?? []);
                    $this->restorePageUrls($page, $state['pageUrls'] ?? []);
                });
            });
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function restoreAttributes(Page $page, array $attributes): void
    {
        $page->forceFill([
            'name' => $attributes['name'] ?? $page->name,
            'blueprint_id' => $attributes['blueprint_id'] ?? $page->blueprint_id,
            'layout_id' => $attributes['layout_id'] ?? $page->layout_id,
            'site_id' => $attributes['site_id'] ?? $page->site_id,
            'parent_id' => $attributes['parent_id'] ?? null,
            'order' => $attributes['order'] ?? null,
            'meta' => $attributes['meta'] ?? null,
            'admin' => $attributes['admin'] ?? null,
            'visible_from' => $attributes['visible_from'] ?? null,
            'visible_until' => $attributes['visible_until'] ?? null,
            'content_structure_override' => $attributes['content_structure_override'] ?? null,
        ])->saveQuietly();
    }

    /**
     * @param  list<array<string, mixed>>  $translations
     */
    private function restoreTranslations(Page $page, array $translations): void
    {
        /** @var Collection<int, Translation> $existing */
        $existing = $page->translations()->withTrashed()->get()->keyBy('language_id');
        $targetLanguageIds = [];

        foreach ($translations as $data) {
            $languageId = $data['language_id'] ?? null;
            $targetLanguageIds[] = $languageId;

            $translation = $existing->get($languageId) ?? $page->translations()->make([
                'language_id' => $languageId,
            ]);

            if ($translation->trashed()) {
                $translation->restore();
            }

            $translation->forceFill([
                'language_id' => $languageId,
                'title' => $data['title'] ?? null,
                'content' => $data['content'] ?? null,
                'meta' => $data['meta'] ?? null,
            ])->saveQuietly();
        }

        $page->translations()
            ->whereNotIn('language_id', $this->withoutNull($targetLanguageIds))
            ->get()
            ->each(static fn (Translation $translation): mixed => $translation->delete());
    }

    /**
     * @param  list<array<string, mixed>>  $pageUrls
     */
    private function restorePageUrls(Page $page, array $pageUrls): void
    {
        /** @var Collection<int, PageUrl> $existing */
        $existing = $page->pageUrls()->withTrashed()->get()->keyBy('url');
        $targetUrls = [];

        foreach ($pageUrls as $data) {
            $url = $data['url'] ?? null;
            $targetUrls[] = $url;

            $existingUrl = $existing->get($url);
            $pageUrl = $existingUrl ?? $page->pageUrls()->make();

            if ($pageUrl->trashed()) {
                $pageUrl->restore();
            }

            $pageUrl->forceFill([
                'language_id' => $data['language_id'] ?? null,
                'site_id' => $data['site_id'] ?? $page->site_id,
                'url' => $url,
                'target_url' => $data['target_url'] ?? null,
                'status_code' => $data['status_code'] ?? null,
                'type' => $data['type'] ?? null,
                'is_manual' => (bool) ($data['is_manual'] ?? false),
                'status' => (bool) ($data['status'] ?? true),
                'notes' => $data['notes'] ?? null,
            ]);

            // Never overwrite an existing (or revived) row's accumulated
            // analytics — a rollback restores content, not visit history. Only
            // a url recreated from scratch seeds its captured historical counts.
            if ($existingUrl === null) {
                $pageUrl->forceFill([
                    'hit_count' => (int) ($data['hit_count'] ?? 0),
                    'last_hit_at' => $data['last_hit_at'] ?? null,
                ]);
            }

            $pageUrl->saveQuietly();
        }

        $page->pageUrls()
            ->whereNotIn('url', $this->withoutNull($targetUrls))
            ->get()
            ->each(static fn (PageUrl $pageUrl): mixed => $pageUrl->delete());
    }

    /**
     * Drop only null entries from a key list — not every falsy value. A
     * language_id of 0 or an empty-string url is a real key that must still
     * participate in the whereNotIn reconciliation.
     *
     * @param  list<mixed>  $values
     * @return list<mixed>
     */
    private function withoutNull(array $values): array
    {
        return array_values(array_filter(
            $values,
            static fn (mixed $value): bool => $value !== null,
        ));
    }

    private function asPage(Model $model): Page
    {
        if (! $model instanceof Page) {
            throw new InvalidArgumentException(sprintf(
                'PageStateSerializer can only serialise %s, got %s.',
                Page::class,
                $model::class,
            ));
        }

        return $model;
    }
}
