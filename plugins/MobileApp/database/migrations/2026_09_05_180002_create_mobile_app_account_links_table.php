<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('mobile_app_account_links')) {
            return;
        }

        Schema::create('mobile_app_account_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('platform', 32);
            $table->string('obfuscated_account_id', 128);
            $table->string('environment', 16);
            $table->string('status', 32)->default('active');
            $table->string('request_id', 36);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['platform', 'obfuscated_account_id'], 'mobile_app_account_links_platform_acct_uq');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_app_account_links');
    }
};
