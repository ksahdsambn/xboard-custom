<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('mobile_app_rtdn_events')) {
            return;
        }

        Schema::create('mobile_app_rtdn_events', function (Blueprint $table): void {
            $table->id();
            $table->string('platform', 32);
            $table->string('event_id', 191);
            $table->string('environment', 16);
            $table->char('payload_digest', 64);
            $table->string('processing_status', 32)->default('received');
            $table->string('request_id', 36);
            $table->string('last_error', 64)->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['platform', 'event_id'], 'mobile_app_rtdn_events_platform_event_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_app_rtdn_events');
    }
};
