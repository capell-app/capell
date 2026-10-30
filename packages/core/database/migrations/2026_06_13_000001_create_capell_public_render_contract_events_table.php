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
        Schema::create(TableName::resolve('capell_public_render_contract_events'), function (Blueprint $table): void {
            $table->id();
            $table->string('result')->index(TableName::index('capell_public_render_contract_events', 'result', 'index'));
            $table->text('reason')->nullable();
            $table->string('matched_marker')->nullable();
            $table->string('package_name')->nullable()->index(TableName::index('capell_public_render_contract_events', 'package_name', 'index'));
            $table->string('source')->nullable()->index(TableName::index('capell_public_render_contract_events', 'source', 'index'));
            $table->string('url_hash')->nullable()->index(TableName::index('capell_public_render_contract_events', 'url_hash', 'index'));
            $table->string('path_hash')->nullable()->index(TableName::index('capell_public_render_contract_events', 'path_hash', 'index'));
            $table->string('response_hash')->nullable()->index(TableName::index('capell_public_render_contract_events', 'response_hash', 'index'));
            $table->foreignId('page_id')->nullable()->index(TableName::index('capell_public_render_contract_events', 'page_id', 'index'));
            $table->foreignId('layout_id')->nullable()->index(TableName::index('capell_public_render_contract_events', 'layout_id', 'index'));
            $table->foreignId('theme_id')->nullable()->index(TableName::index('capell_public_render_contract_events', 'theme_id', 'index'));
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index('created_at', TableName::namedIndex('capell_public_render_contract_events', 'capell_render_contract_created_idx'));
            $table->index(['result', 'created_at'], TableName::namedIndex('capell_public_render_contract_events', 'capell_render_contract_result_created_idx'));
            $table->index(['package_name', 'result', 'created_at'], TableName::namedIndex('capell_public_render_contract_events', 'capell_render_contract_package_result_idx'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('capell_public_render_contract_events'));
    }
};
