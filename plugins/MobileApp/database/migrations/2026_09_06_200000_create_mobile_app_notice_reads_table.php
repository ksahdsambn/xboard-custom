<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('mobile_app_notice_reads')) {
            return;
        }

        Schema::create('mobile_app_notice_reads', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('notice_id');
            $table->timestamp('read_at')->nullable();
            $table->string('request_id', 36);
            $table->string('environment', 16);
            $table->timestamps();
            $table->unique(['user_id', 'notice_id'], 'mobile_app_notice_reads_user_notice_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_app_notice_reads');
    }
};
