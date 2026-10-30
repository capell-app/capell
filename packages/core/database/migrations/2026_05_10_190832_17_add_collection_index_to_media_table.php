<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! TableName::externalExistsAndIsOwned((string) config('capell.database.external_tables.media', 'media'), 'create_media_table')) {
            return;
        }

        if (Schema::hasIndex((string) config('capell.database.external_tables.media', 'media'), $this->indexName())) {
            return;
        }

        Schema::table((string) config('capell.database.external_tables.media', 'media'), function (Blueprint $table): void {
            $table->index(['model_type', 'model_id', 'collection_name', 'order_column'], $this->indexName());
        });
    }

    public function down(): void
    {
        if (! TableName::externalExistsAndIsOwned((string) config('capell.database.external_tables.media', 'media'), 'create_media_table')) {
            return;
        }

        if (! Schema::hasIndex((string) config('capell.database.external_tables.media', 'media'), $this->indexName())) {
            return;
        }

        Schema::table((string) config('capell.database.external_tables.media', 'media'), function (Blueprint $table): void {
            $table->dropIndex($this->indexName());
        });
    }

    private function indexName(): string
    {
        $table = (string) config('capell.database.external_tables.media', 'media');
        $name = strtolower(str_replace(['-', '.'], '_', $table . '_collection_name_index'));

        return strlen($name) <= 63 ? $name : substr($name, 0, 54) . '_' . substr(hash('sha256', $name), 0, 8);
    }
};
