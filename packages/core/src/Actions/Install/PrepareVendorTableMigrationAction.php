<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class PrepareVendorTableMigrationAction
{
    use AsObject;

    public function handle(string $migration, string $source): string
    {
        if ($migration === 'create_tag_tables') {
            foreach (['tags' => 'capell.database.external_tables.tags', 'taggables' => 'tags.taggable.table_name'] as $table => $key) {
                $source = str_replace(
                    ["Schema::create('" . $table . "'", "Schema::dropIfExists('" . $table . "'"],
                    ["Schema::create((string) config('" . $key . "', '" . $table . "')", "Schema::dropIfExists((string) config('" . $key . "', '" . $table . "')"],
                    $source,
                    $count,
                );
                throw_unless($count >= 1 && $count <= 2, RuntimeException::class, 'Cannot safely configure the published Tags migration: its table declaration is unexpected.');
            }

            $source = str_replace("->foreignId('tag_id')->constrained()", "->foreignId('tag_id')->constrained((string) config('capell.database.external_tables.tags', 'tags'))", $source, $count);
            throw_unless($count === 1, RuntimeException::class, 'Cannot safely configure the published Tags migration: its foreign key is unexpected.');

            return $source;
        }

        if ($migration !== 'create_media_table') {
            return $source;
        }

        $rewritten = str_replace(
            "Schema::create('media'",
            "Schema::create((string) config('capell.database.external_tables.media', 'media')",
            $source,
            $count,
        );
        throw_unless($count === 1, RuntimeException::class, 'Cannot safely configure the published media migration: its table declaration is unexpected.');

        return $rewritten;
    }
}
