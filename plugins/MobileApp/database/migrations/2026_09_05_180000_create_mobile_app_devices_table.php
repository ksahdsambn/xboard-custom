<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('mobile_app_devices')) {
            return;
        }

        Schema::create('mobile_app_devices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('opaque_device_id', 64);
            $table->string('platform', 16);
            $table->string('app_version', 32);
            $table->unsignedSmallInteger('android_api')->nullable();
            $table->unsignedTinyInteger('mobile_api_version');
            $table->unsignedTinyInteger('profile_schema_version');
            $table->string('libxray_version', 64)->nullable();
            $table->string('xray_core_version', 64)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('request_id', 36);
            $table->string('environment', 16);
            $table->timestamps();
            $table->unique(['user_id', 'opaque_device_id'], 'mobile_app_devices_user_device_uq');
            $table->index('platform');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_app_devices');
    }
};
