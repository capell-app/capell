<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Sites;

use Capell\Core\Models\Language;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Rebuilds the site wizard's domain rows after the selected languages change
 * while keeping every row the person has already edited.
 *
 * @method static list<array{url: string, language_id: int, default: bool, use_host_domain: bool}> run(array<int|string, mixed> $existing, array<int|string, mixed> $languageIds, string $baseUrl)
 */
class ReconcileWizardSiteDomainsAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<int|string, mixed>  $existing  Current wizard domain rows, in the order the person left them.
     * @param  array<int|string, mixed>  $languageIds  Selected languages, primary language first.
     * @return list<array<string, mixed>>
     */
    public function handle(array $existing, array $languageIds, string $baseUrl): array
    {
        $languageIds = array_values(array_unique(array_map(intval(...), array_filter($languageIds, is_numeric(...)))));

        $languages = Language::query()
            ->whereIn('id', $languageIds)
            ->orderByDesc('default')
            ->get()
            ->keyBy('id');

        $kept = [];
        foreach ($existing as $row) {
            if (is_array($row) && $languages->has((int) ($row['language_id'] ?? 0))) {
                $kept[] = $row;
            }
        }

        $coveredLanguages = array_map(static fn (array $row): int => (int) $row['language_id'], $kept);

        $rows = $kept;
        foreach ($languages as $language) {
            if (in_array($language->id, $coveredLanguages, true)) {
                continue;
            }

            $rows[] = [
                'url' => $baseUrl . ($language->default ? '' : '/' . $language->code),
                'language_id' => $language->id,
                'default' => false,
                'use_host_domain' => true,
            ];
        }

        return $this->withSingleDefault($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withSingleDefault(array $rows): array
    {
        $defaultIndex = array_find_key($rows, static fn (array $row): bool => ($row['default'] ?? false) === true);
        $defaultIndex ??= array_key_first($rows);

        foreach (array_keys($rows) as $index) {
            $rows[$index]['default'] = $index === $defaultIndex;
        }

        return $rows;
    }
}
