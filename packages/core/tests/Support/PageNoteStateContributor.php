<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\EventSourcing\Contracts\PageStateContributor;
use Capell\Core\Models\Page;
use Illuminate\Support\Facades\DB;
use Override;

/**
 * Test contributor standing in for an optional package that owns page state
 * in its own table (`page_state_fixture_notes`).
 */
final class PageNoteStateContributor implements PageStateContributor
{
    public const string TABLE = 'page_state_fixture_notes';

    #[Override]
    public function key(): string
    {
        return 'fixtureNotes';
    }

    #[Override]
    public function capture(Page $page): array
    {
        return DB::table(self::TABLE)
            ->where('page_id', $page->getKey())
            ->orderBy('position')
            ->get(['body', 'position'])
            ->map(static fn (object $row): array => ['body' => $row->body, 'position' => (int) $row->position])
            ->all();
    }

    #[Override]
    public function restore(Page $page, array $state): void
    {
        DB::table(self::TABLE)->where('page_id', $page->getKey())->delete();

        foreach ($state as $row) {
            DB::table(self::TABLE)->insert([
                'page_id' => $page->getKey(),
                'body' => $row['body'],
                'position' => $row['position'],
            ]);
        }
    }
}
