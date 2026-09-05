<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('mobile_app_security_audits')) {
            return;
        }

        Schema::create('mobile_app_security_audits', function (Blueprint $table): void {
            $table->id();
            $table->string('request_id', 36);
            $table->string('operation', 32);
            $table->string('outcome', 16);
            $table->string('error_code', 64)->nullable();
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('actor_opaque_id', 64)->nullable();
            $table->string('route', 128)->nullable();
            $table->string('environment', 16)->default('testing');
            $table->json('meta_json')->nullable();
            $table->timestamps();
            $table->index(['operation', 'created_at']);
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_app_security_audits');
    }
};
