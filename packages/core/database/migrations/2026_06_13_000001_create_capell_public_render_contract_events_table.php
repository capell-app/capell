<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capell_public_render_contract_events', function (Blueprint $table): void {
            $table->id();
            $table->string('result')->index();
            $table->text('reason')->nullable();
            $table->string('matched_marker')->nullable();
            $table->string('package_name')->nullable()->index();
            $table->string('source')->nullable()->index();
            $table->string('url_hash')->nullable()->index();
            $table->string('path_hash')->nullable()->index();
            $table->string('response_hash')->nullable()->index();
            $table->foreignId('page_id')->nullable()->index();
            $table->foreignId('layout_id')->nullable()->index();
            $table->foreignId('theme_id')->nullable()->index();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index('created_at', 'capell_render_contract_created_idx');
            $table->index(['result', 'created_at'], 'capell_render_contract_result_created_idx');
            $table->index(['package_name', 'result', 'created_at'], 'capell_render_contract_package_result_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capell_public_render_contract_events');
    }
};
