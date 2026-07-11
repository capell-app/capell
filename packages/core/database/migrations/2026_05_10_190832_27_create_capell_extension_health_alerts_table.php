<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capell_extension_health_alerts', function (Blueprint $table): void {
            $table->id();
            $table->string('alert_id')->unique();
            $table->string('source')->index();
            $table->string('extension_slug')->nullable()->index();
            $table->string('composer_name')->nullable()->index();
            $table->string('affected_site_id')->nullable()->index();
            $table->string('affected_install_id')->nullable()->index();
            $table->string('severity')->index();
            $table->string('category')->index();
            $table->string('title');
            $table->text('message');
            $table->string('required_action')->nullable();
            $table->boolean('runtime_disabled')->default(false);
            $table->boolean('protected_actions_blocked')->default(false);
            $table->timestamp('issued_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->text('signature');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['severity', 'extension_slug', 'expires_at', 'issued_at'], 'capell_health_alerts_severity_slug_expiry_idx');
            $table->index(['severity', 'composer_name', 'expires_at', 'issued_at'], 'capell_health_alerts_severity_composer_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capell_extension_health_alerts');
    }
};
