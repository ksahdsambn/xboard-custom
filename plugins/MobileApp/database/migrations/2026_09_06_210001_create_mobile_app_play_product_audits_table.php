<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('mobile_app_play_product_audits')) {
            return;
        }

        Schema::create('mobile_app_play_product_audits', function (Blueprint $table): void {
            $table->id();
            $table->string('action', 32);
            $table->unsignedBigInteger('play_product_id')->nullable();
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('request_id', 36);
            $table->string('environment', 16);
            $table->timestamps();
            $table->index(['play_product_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_app_play_product_audits');
    }
};
