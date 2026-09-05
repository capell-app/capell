<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Serializers;

use Capell\Core\Actions\SetupPageUrlsAction;
use Capell\Core\EventSourcing\Contracts\EventSourcedStateSerializer;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Translation;
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
 * Raw snapshot replay runs event-silent (Model::withoutEvents) so it never
 * re-triggers the recording bridge; general rollback side-effects are driven by
 * the reactor reacting to PageRolledBack. An empty pageUrls snapshot is the
 * deliberate exception: its derived canonical URL is synthesised inline after
 * silent replay through the ordinary, collision-checked PageUrl save path.
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
            // CAP-0460: property values are single-copy, exactly like the
            // page's own title/content above — no separate draft/published
            // projection exists to snapshot, so the current row set IS the
            // state to capture. Loaded fresh (not loadMissing) for the same
            // reason as translations/pageUrls above.
            'propertyValues' => $page->propertyValues()->get()
                ->map(static fn (PagePropertyValue $value): array => [
                    'property_definition_id' => $value->property_definition_id,
                    'translation_id' => $value->translation_id,
                    'position' => $value->position,
                    'value_text' => $value->value_text,
                    'value_number' => $value->getRawOriginal('value_number'),
                    'value_boolean' => $value->value_boolean,
                    'value_datetime' => $value->value_datetime?->toIso8601String(),
                    'currency' => $value->currency,
                    'unit' => $value->unit,
                    'term_id' => $value->term_id,
                    'referenced_page_id' => $value->referenced_page_id,
                    'media_id' => $value->media_id,
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

        DB::transaction(function () use ($page, $state): void {
            Model::withoutEvents(function () use ($page, $state): void {
                $this->restoreAttributes($page, $state['attributes'] ?? []);
                $this->restoreTranslations($page, $state['translations'] ?? []);
                $this->restorePageUrls($page, $state['pageUrls'] ?? []);
                $this->restorePropertyValues($page, $state['propertyValues'] ?? []);
            });

            // The PageSaved bridge can record the initial authoring revision
            // before the Translation saved listener has created its derived
            // canonical PageUrl. Rebuild it after event-silent replay so the
            // normal PageUrl saving observer still rejects live collisions.
            if (($state['pageUrls'] ?? []) === []) {
                SetupPageUrlsAction::run($page, updateDescendants: false);
            }
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
     * Unlike pageUrls (which preserve accumulated analytics across a
     * rollback), property values carry no state worth preserving outside the
     * captured snapshot itself — a straight delete-and-recreate reproduces
     * the target state exactly, byte-identical, the same guarantee the class
     * docblock promises for translation content.
     *
     * @param  list<array<string, mixed>>  $propertyValues
     */
    private function restorePropertyValues(Page $page, array $propertyValues): void
    {
        $page->propertyValues()->delete();

        foreach ($propertyValues as $data) {
            $page->propertyValues()->create([
                'site_id' => $page->site_id,
                'property_definition_id' => $data['property_definition_id'] ?? null,
                'translation_id' => $data['translation_id'] ?? null,
                'position' => $data['position'] ?? 0,
                'value_text' => $data['value_text'] ?? null,
                'value_number' => $data['value_number'] ?? null,
                'value_boolean' => $data['value_boolean'] ?? null,
                'value_datetime' => $data['value_datetime'] ?? null,
                'currency' => $data['currency'] ?? null,
                'unit' => $data['unit'] ?? null,
                'term_id' => $data['term_id'] ?? null,
                'referenced_page_id' => $data['referenced_page_id'] ?? null,
                'media_id' => $data['media_id'] ?? null,
            ]);
        }
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
