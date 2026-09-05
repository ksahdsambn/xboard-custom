<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('mobile_app_entitlement_projections')) {
            return;
        }

        Schema::create('mobile_app_entitlement_projections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('source', 16);
            $table->unsignedBigInteger('purchase_token_id')->nullable();
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->unsignedBigInteger('expire_at')->nullable();
            $table->unsignedBigInteger('traffic_bytes')->nullable();
            $table->string('idempotency_key', 191);
            $table->string('request_id', 36);
            $table->string('status', 32);
            $table->string('environment', 16);
            $table->timestamps();
            $table->unique('idempotency_key', 'mobile_app_entitlement_proj_idem_uq');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_app_entitlement_projections');
    }
};
