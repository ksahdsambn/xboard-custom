<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('mobile_app_purchase_tokens')) {
            return;
        }
        $columns = [
            'granted_at' => fn (Blueprint $table) => $table->timestamp('granted_at')->nullable(),
            'acknowledged_at' => fn (Blueprint $table) => $table->timestamp('acknowledged_at')->nullable(),
            'verified_at' => fn (Blueprint $table) => $table->timestamp('verified_at')->nullable(),
            'is_renewal' => fn (Blueprint $table) => $table->boolean('is_renewal')->default(false),
        ];
        foreach ($columns as $name => $define) {
            if (Schema::hasColumn('mobile_app_purchase_tokens', $name)) {
                continue;
            }
            Schema::table('mobile_app_purchase_tokens', function (Blueprint $table) use ($define): void {
                $define($table);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('mobile_app_purchase_tokens')) {
            return;
        }
        foreach (['granted_at', 'acknowledged_at', 'verified_at', 'is_renewal'] as $column) {
            if (!Schema::hasColumn('mobile_app_purchase_tokens', $column)) {
                continue;
            }
            Schema::table('mobile_app_purchase_tokens', function (Blueprint $table) use ($column): void {
                $table->dropColumn($column);
            });
        }
    }
};
