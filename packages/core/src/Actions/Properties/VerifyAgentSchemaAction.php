<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Properties;

use Capell\Core\Data\Properties\AgentSchemaReportData;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;
use Capell\Core\Models\Site;
use Capell\Core\Models\TermPropertyValue;
use Capell\Core\Support\Properties\BuiltInPropertySets;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class VerifyAgentSchemaAction
{
    use AsFake;
    use AsObject;

    public function handle(?int $siteId = null): AgentSchemaReportData
    {
        $failures = [];
        $fail = static function (string $check, string $subject, string $problem) use (&$failures): void {
            $failures[] = ['check' => $check, 'subject' => $subject, 'problem' => $problem];
        };
        foreach (['sites', 'pages', 'blueprint_property_sets', 'capell_extensions', 'property_sets', 'property_definitions', 'page_property_values', 'term_property_values'] as $table) {
            if (! Schema::hasTable($table)) {
                $fail('schema', $table, 'Required table is missing.');
            }
        }

        if ($failures !== []) {
            return new AgentSchemaReportData($failures, 0);
        }

        if ($siteId !== null && ! Site::query()->whereKey($siteId)->exists()) {
            $fail('site', (string) $siteId, 'Site does not exist.');

            return new AgentSchemaReportData($failures, 0);
        }

        $vocabulary = json_decode(File::get(__DIR__ . '/../../../resources/agent-schema/schemaorg-terms.json'), true, flags: JSON_THROW_ON_ERROR);
        $terms = array_fill_keys($vocabulary['terms'], true);
        foreach (PropertyDefinition::query()->with('propertySet')->cursor() as $definition) {
            $semantic = $definition->semantic;
            if ($semantic !== null && (! str_starts_with($semantic, 'schema:') || ! isset($terms[substr($semantic, 7)]))) {
                $fail('semantic', (string) $definition->id, 'Unknown schema.org term: ' . $semantic);
            }

            if (! $definition->propertySet instanceof PropertySet) {
                $fail('orphan', (string) $definition->id, 'Property definition has no property set.');
            }
        }

        $installedOwners = CapellExtension::query()->whereNotNull('installed_at')->pluck('composer_name')->all();
        foreach (PropertySet::query()->get() as $set) {
            if ($set->owner_package !== null && $set->owner_package !== BuiltInPropertySets::OWNER_PACKAGE && ! in_array($set->owner_package, $installedOwners, true)) {
                $fail('owner', $set->key, 'Owning package is not installed: ' . $set->owner_package);
            }
        }

        foreach ([PagePropertyValue::class, TermPropertyValue::class] as $model) {
            $count = $model::query()->whereDoesntHave('propertyDefinition')->count();
            if ($count > 0) {
                $fail('orphan', (new $model)->getTable(), sprintf('%d values have no definition.', $count));
            }
        }

        foreach (BuiltInPropertySets::all() as $key => $expectedSet) {
            $set = PropertySet::query()->where('key', $key)->first();
            if (! $set instanceof PropertySet) {
                $fail('builtin', $key, 'Built-in property set is missing.');

                continue;
            }

            foreach ($expectedSet['definitions'] as $expected) {
                $actual = $set->definitions()->where('key', $expected['key'])->first();
                foreach (['type', 'semantic', 'requirement', 'locked', 'unit_config'] as $attribute) {
                    if ($actual === null || $actual->{$attribute} !== $expected[$attribute]) {
                        $fail('builtin', $key . '.' . $expected['key'], 'Built-in definition differs: ' . $attribute);
                    }
                }
            }
        }

        $checked = 0;
        $incomplete = [];
        foreach (Page::query()->published()->when($siteId !== null, fn ($query) => $query->where('site_id', $siteId))->cursor() as $page) {
            $checked++;
            if (! EvaluatePropertyCompletenessAction::run($page)->isAgentComplete()) {
                $key = sprintf('site:%d blueprint:%d', $page->site_id, $page->blueprint_id);
                $incomplete[$key] = ($incomplete[$key] ?? 0) + 1;
            }
        }

        foreach ($incomplete as $key => $count) {
            $fail('completeness', $key, sprintf('%d published pages have missing required properties.', $count));
        }

        return new AgentSchemaReportData($failures, $checked);
    }
}
