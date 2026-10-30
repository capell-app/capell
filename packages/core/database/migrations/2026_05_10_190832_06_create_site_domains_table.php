<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
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
        Schema::create(TableName::resolve('site_domains'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained(TableName::resolve('sites'), indexName: TableName::index('site_domains', 'site_id', 'foreign'))->cascadeOnDelete();
            $table->foreignId('language_id')->nullable()->constrained(TableName::resolve('languages'), indexName: TableName::index('site_domains', 'language_id', 'foreign'))->cascadeOnDelete();
            $table->string('domain')->nullable();
            $table->string('scheme', 12)->nullable();
            $table->string('path', 125)->nullable();
            $table->boolean('status')->index(TableName::index('site_domains', 'status', 'index'))->default(1);
            $table->boolean('default')->default(0);
            $table->softDeletes();
            $table->userstamps();
            $table->timestamps();
            $table->index(['site_id', 'default'], TableName::index('site_domains', ['site_id', 'default'], 'index'));
            $table->index(['domain', 'scheme', 'status'], TableName::namedIndex('site_domains', 'site_domains_domain_scheme_status_index'));
            $table->unique(['scheme', 'domain', 'path', 'deleted_at'], TableName::namedIndex('site_domains', 'site_domains_unique'));
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('site_domains'));
    }
};
