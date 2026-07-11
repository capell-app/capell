<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read-model projection of a page's editorial workflow status, rebuilt from
 * the event stream by PageProjector. One row per page (keyed by page uuid).
 *
 * This backs the richer editorial fields (who approved, who requested changes)
 * that the inferred PagePublishStateData cannot express. The legacy publish
 * columns (visible_from/visible_until) remain the source of truth for public
 * visibility and are kept in sync by the same projector.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_workflow_states', function (Blueprint $table): void {
            $table->id();
            $table->char('page_uuid', 36);
            $table->string('status')->default('draft');
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('requested_changes_note')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->unsignedBigInteger('aggregate_version')->default(0);
            $table->timestamps();

            $table->unique('page_uuid');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_workflow_states');
    }
};
