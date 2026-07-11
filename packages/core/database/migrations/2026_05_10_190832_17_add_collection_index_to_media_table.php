<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('media')) {
            return;
        }

        if (Schema::hasIndex('media', 'media_collection_name_index')) {
            return;
        }

        Schema::table('media', function (Blueprint $table): void {
            $table->index(['model_type', 'model_id', 'collection_name', 'order_column'], 'media_collection_name_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('media')) {
            return;
        }

        if (! Schema::hasIndex('media', 'media_collection_name_index')) {
            return;
        }

        Schema::table('media', function (Blueprint $table): void {
            $table->dropIndex('media_collection_name_index');
        });
    }
};
