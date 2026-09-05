<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('mobile_app_compat_audits')) {
            return;
        }

        Schema::create('mobile_app_compat_audits', function (Blueprint $table): void {
            $table->id();
            $table->string('action', 32);
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->string('reason', 64)->nullable();
            $table->string('evidence_ref', 128)->nullable();
            $table->string('approved_by', 64)->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('request_id', 36);
            $table->string('environment', 16)->default('testing');
            $table->timestamps();
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_app_compat_audits');
    }
};
