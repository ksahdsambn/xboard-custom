<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('mobile_app_entitlement_projections') && !Schema::hasColumn('mobile_app_entitlement_projections', 'baseline_plan_id')) {
            Schema::table('mobile_app_entitlement_projections', function (Blueprint $table): void {
                $table->unsignedBigInteger('baseline_plan_id')->nullable();
                $table->unsignedBigInteger('baseline_expired_at')->nullable();
                $table->unsignedBigInteger('baseline_transfer_enable')->nullable();
                $table->unsignedInteger('baseline_group_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('mobile_app_entitlement_projections') && Schema::hasColumn('mobile_app_entitlement_projections', 'baseline_plan_id')) {
            Schema::table('mobile_app_entitlement_projections', function (Blueprint $table): void {
                $table->dropColumn([
                    'baseline_plan_id',
                    'baseline_expired_at',
                    'baseline_transfer_enable',
                    'baseline_group_id',
                ]);
            });
        }
    }
};
