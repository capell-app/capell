<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Activity;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\AssetAttachment;
use Capell\Core\Models\ContentLock;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageRevision;
use Capell\Core\Models\PageWorkflowState;
use Capell\Core\Models\Site;
use Capell\Core\Models\Taxonomy;
use Capell\Core\Models\Term;
use Capell\Core\Models\TermPropertyValue;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/** Admin authorisation for a proposed restore; console replay remains actor-free. */
final class ProposedActivityRevertAccess
{
    /** @param array<string, mixed> $attributes
     * @param  array<string, mixed>  $state
     */
    public function allows(Model $subject, array $attributes, array $state = []): bool
    {
        $access = SiteAccess::current();
        $proposed = clone $subject;
        $proposed->unsetRelations()->forceFill($attributes);

        if (($subject->hasAttribute('site_id') || $subject instanceof Site || $subject instanceof Media
            || $subject instanceof Translation || $subject instanceof Term || $subject instanceof TermPropertyValue
            || $subject instanceof AssetAttachment || $subject instanceof ContentLock
            || $subject instanceof PageRevision || $subject instanceof PageWorkflowState)
            && (! $access->canUseRecord($subject) || ! $access->canUseRecord($proposed))) {
            return false;
        }

        // Casts store JSON as strings; retain structured restored values so
        // ownership-bearing metadata cannot disappear from the access check.
        return $this->referencesAllowed($access, array_replace($proposed->getAttributes(), $attributes), $subject instanceof Term ? Term::class : Page::class)
            && $this->referencesAllowed($access, $state);
    }

    /** @param array<array-key, mixed> $values
     * @param  class-string<Model>  $parentModel
     */
    private function referencesAllowed(SiteAccess $access, array $values, string $parentModel = Page::class): bool
    {
        foreach (['canonical_pageable', 'pageable'] as $reference) {
            $id = $values[$reference . '_id'] ?? null;
            if ($id === null) {
                continue;
            }

            $type = $values[$reference . '_type'] ?? null;
            $model = is_string($type) ? Relation::getMorphedModel($type) : null;
            if (! is_int($id) && ! is_string($id) || $model === null || ! is_a($model, Pageable::class, true)
                || ! $access->query($model)->whereKey($id)->exists()) {
                return false;
            }
        }

        $references = [
            'site_id' => Site::class, 'layout_id' => Layout::class,
            'parent_id' => $parentModel, 'page_id' => Page::class, 'referenced_page_id' => Page::class,
            'term_id' => Term::class, 'referenced_term_id' => Term::class, 'taxonomy_id' => Taxonomy::class,
            'media_id' => Media::class, 'translation_id' => Translation::class,
        ];

        foreach ($values as $field => $value) {
            if (is_array($value)) {
                if (! $this->referencesAllowed($access, $value, $parentModel)) {
                    return false;
                }
            } elseif ($value !== null && isset($references[$field])
                && (! is_int($value) && ! is_string($value) || ! $access->query($references[$field])->whereKey($value)->exists())) {
                return false;
            }
        }

        return true;
    }
}
