<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('mobile_app_rtdn_events') && !Schema::hasColumn('mobile_app_rtdn_events', 'purchase_token_hash')) {
            Schema::table('mobile_app_rtdn_events', function (Blueprint $table): void {
                $table->char('purchase_token_hash', 64)->nullable();
                $table->unsignedBigInteger('event_time_millis')->nullable();
                $table->unsignedInteger('claimed_notification_type')->nullable();
                $table->string('play_status_applied', 32)->nullable();
                $table->char('applied_digest', 64)->nullable();
                $table->unsignedInteger('apply_count')->default(0);
            });
        }
        if (Schema::hasTable('mobile_app_purchase_tokens') && !Schema::hasColumn('mobile_app_purchase_tokens', 'last_applied_digest')) {
            Schema::table('mobile_app_purchase_tokens', function (Blueprint $table): void {
                $table->char('last_applied_digest', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('mobile_app_rtdn_events') && Schema::hasColumn('mobile_app_rtdn_events', 'purchase_token_hash')) {
            Schema::table('mobile_app_rtdn_events', function (Blueprint $table): void {
                $table->dropColumn([
                    'purchase_token_hash',
                    'event_time_millis',
                    'claimed_notification_type',
                    'play_status_applied',
                    'applied_digest',
                    'apply_count',
                ]);
            });
        }
        if (Schema::hasTable('mobile_app_purchase_tokens') && Schema::hasColumn('mobile_app_purchase_tokens', 'last_applied_digest')) {
            Schema::table('mobile_app_purchase_tokens', function (Blueprint $table): void {
                $table->dropColumn('last_applied_digest');
            });
        }
    }
};
