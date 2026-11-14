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
        Schema::create(TableName::resolve('capell_ai_provenance'), function (Blueprint $table): void {
            $table->id();
            $table->string('pageable_type', 120);
            $table->unsignedBigInteger('pageable_id');
            $table->unsignedBigInteger('language_id')->nullable();
            $table->string('field', 191);
            // Optional packages own these records, so they are plain references, not foreign keys.
            $table->unsignedBigInteger('generation_history_id')->nullable();
            $table->string('provider', 120)->nullable();
            $table->string('model', 191)->nullable();
            $table->string('capability', 120)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->string('disclosure', 20);
            $table->timestamps();

            $table->unique(['pageable_type', 'pageable_id', 'language_id', 'field'], TableName::namedIndex('capell_ai_provenance', 'capell_ai_provenance_field_unique'));
            $table->index(['pageable_type', 'pageable_id', 'confirmed_at'], TableName::namedIndex('capell_ai_provenance', 'capell_ai_provenance_pageable_confirmed_index'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('capell_ai_provenance'));
    }
};
