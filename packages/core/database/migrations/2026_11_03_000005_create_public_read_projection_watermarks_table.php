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
        Schema::create(TableName::resolve('public_read_projection_watermarks'), function (Blueprint $table): void {
            $table->id();
            $table->string('step', 120);
            $table->string('scope', 40);
            $table->unsignedBigInteger('site_id')->nullable();
            // The public-read stamp the store's copies for this scope were last withdrawn under.
            $table->string('stamp_key', 120)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['step', 'scope'], TableName::namedIndex('public_read_projection_watermarks', 'public_read_projection_watermarks_unique'));
            $table->index('attempted_at', TableName::namedIndex('public_read_projection_watermarks', 'public_read_projection_watermarks_attempted'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('public_read_projection_watermarks'));
    }
};
