<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('mobile_app_play_products')) {
            return;
        }
        $exists = false;
        try {
            $exists = method_exists(Schema::class, 'hasIndex')
                && Schema::hasIndex('mobile_app_play_products', 'mobile_app_play_products_product_env_uq');
        } catch (\Throwable) {
            $exists = false;
        }
        if ($exists) {
            return;
        }
        try {
            Schema::table('mobile_app_play_products', function (Blueprint $table): void {
                $table->unique(
                    ['package_name', 'product_id', 'environment'],
                    'mobile_app_play_products_product_env_uq'
                );
            });
        } catch (\Throwable $exception) {
            $message = strtolower($exception->getMessage());
            if (!str_contains($message, 'already exists') && !str_contains($message, 'duplicate')) {
                throw $exception;
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('mobile_app_play_products')) {
            return;
        }
        if (!Schema::hasIndex('mobile_app_play_products', 'mobile_app_play_products_product_env_uq')) {
            return;
        }
        Schema::table('mobile_app_play_products', function (Blueprint $table): void {
            $table->dropUnique('mobile_app_play_products_product_env_uq');
        });
    }
};
