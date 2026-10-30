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
        if (! TableName::externalExistsAndIsOwned((string) config('capell.database.external_tables.media', 'media'), 'create_media_table') || Schema::hasColumn((string) config('capell.database.external_tables.media', 'media'), 'deleted_at')) {
            return;
        }

        Schema::table((string) config('capell.database.external_tables.media', 'media'), function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (! TableName::externalExistsAndIsOwned((string) config('capell.database.external_tables.media', 'media'), 'create_media_table') || ! Schema::hasColumn((string) config('capell.database.external_tables.media', 'media'), 'deleted_at')) {
            return;
        }

        Schema::table((string) config('capell.database.external_tables.media', 'media'), function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
