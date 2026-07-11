<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capell_admin_notification_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->morphs('user', 'capell_admin_notification_subscriptions_user_idx');
            $table->string('group_key')->index();
            $table->boolean('subscribed')->default(true);
            $table->timestamps();

            $table->unique(['user_type', 'user_id', 'group_key'], 'capell_admin_notification_subscriptions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capell_admin_notification_subscriptions');
    }
};
