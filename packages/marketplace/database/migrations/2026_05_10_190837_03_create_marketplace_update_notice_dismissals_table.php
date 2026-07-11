<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_update_notice_dismissals')) {
            Schema::create('marketplace_update_notice_dismissals', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('notice_id');
                $table->timestamp('dismissed_until')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'notice_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_update_notice_dismissals');
    }
};
