<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('mobile_app_compat_settings')) {
            return;
        }

        Schema::create('mobile_app_compat_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('maintenance')->default(false);
            $table->boolean('region_unavailable')->default(false);
            $table->json('blocked_regions')->nullable();
            $table->string('minimum_app_version', 32)->default('1.0.0');
            $table->string('suggested_app_version', 32)->default('1.0.0');
            $table->unsignedSmallInteger('minimum_android_api')->default(26);
            $table->boolean('purchase_enabled')->default(true);
            $table->boolean('connect_enabled')->default(true);
            $table->json('disabled_kernel_versions')->nullable();
            $table->boolean('force_upgrade_enabled')->default(false);
            $table->string('force_upgrade_reason', 64)->nullable();
            $table->string('force_upgrade_evidence_ref', 128)->nullable();
            $table->string('force_upgrade_approved_by', 64)->nullable();
            $table->boolean('wallet_enabled')->default(false);
            $table->string('updated_request_id', 36)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_app_compat_settings');
    }
};
