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
        Schema::create(TableName::resolve('capell_extension_health_alerts'), function (Blueprint $table): void {
            $table->id();
            $table->string('alert_id')->unique(TableName::index('capell_extension_health_alerts', 'alert_id', 'unique'));
            $table->string('source')->index(TableName::index('capell_extension_health_alerts', 'source', 'index'));
            $table->string('extension_slug')->nullable()->index(TableName::index('capell_extension_health_alerts', 'extension_slug', 'index'));
            $table->string('composer_name')->nullable()->index(TableName::index('capell_extension_health_alerts', 'composer_name', 'index'));
            $table->string('affected_site_id')->nullable()->index(TableName::index('capell_extension_health_alerts', 'affected_site_id', 'index'));
            $table->string('affected_install_id')->nullable()->index(TableName::index('capell_extension_health_alerts', 'affected_install_id', 'index'));
            $table->string('severity')->index(TableName::index('capell_extension_health_alerts', 'severity', 'index'));
            $table->string('category')->index(TableName::index('capell_extension_health_alerts', 'category', 'index'));
            $table->string('title');
            $table->text('message');
            $table->string('required_action')->nullable();
            $table->boolean('runtime_disabled')->default(false);
            $table->boolean('protected_actions_blocked')->default(false);
            $table->timestamp('issued_at')->nullable()->index(TableName::index('capell_extension_health_alerts', 'issued_at', 'index'));
            $table->timestamp('expires_at')->nullable()->index(TableName::index('capell_extension_health_alerts', 'expires_at', 'index'));
            $table->text('signature');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['severity', 'extension_slug', 'expires_at', 'issued_at'], TableName::namedIndex('capell_extension_health_alerts', 'capell_health_alerts_severity_slug_expiry_idx'));
            $table->index(['severity', 'composer_name', 'expires_at', 'issued_at'], TableName::namedIndex('capell_extension_health_alerts', 'capell_health_alerts_severity_composer_expiry_idx'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('capell_extension_health_alerts'));
    }
};
