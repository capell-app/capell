<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('site_domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('language_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('domain')->nullable();
            $table->string('scheme', 12)->nullable();
            $table->string('path', 125)->nullable();
            $table->boolean('status')->index()->default(1);
            $table->boolean('default')->default(0);
            $table->softDeletes();
            $table->userstamps();
            $table->timestamps();
            $table->index(['site_id', 'default']);
            $table->index(['domain', 'scheme', 'status'], 'site_domains_domain_scheme_status_index');
            $table->unique(['scheme', 'domain', 'path', 'deleted_at'], 'site_domains_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_domains');
    }
};
