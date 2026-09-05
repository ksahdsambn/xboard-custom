<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('mobile_app_play_products')) {
            return;
        }

        Schema::create('mobile_app_play_products', function (Blueprint $table): void {
            $table->id();
            $table->string('package_name', 191);
            $table->string('product_id', 191);
            $table->string('base_plan_id', 191)->default('');
            $table->string('environment', 16);
            $table->unsignedBigInteger('xboard_plan_id');
            $table->boolean('enabled')->default(true);
            $table->string('request_id', 36)->nullable();
            $table->timestamps();
            $table->unique(
                ['package_name', 'product_id', 'base_plan_id', 'environment'],
                'mobile_app_play_products_map_uq'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_app_play_products');
    }
};
