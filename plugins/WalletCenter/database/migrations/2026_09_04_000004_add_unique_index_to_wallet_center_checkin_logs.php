<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('wallet_center_checkin_logs')) {
            return;
        }

        $duplicates = DB::table('wallet_center_checkin_logs')
            ->select('user_id', 'claim_date', DB::raw('MIN(id) as keep_id'))
            ->groupBy('user_id', 'claim_date')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::table('wallet_center_checkin_logs')
                ->where('user_id', $duplicate->user_id)
                ->whereDate('claim_date', $duplicate->claim_date)
                ->where('id', '<>', $duplicate->keep_id)
                ->delete();
        }

        try {
            Schema::table('wallet_center_checkin_logs', function (Blueprint $table): void {
                $table->unique(['user_id', 'claim_date'], 'wallet_center_checkin_user_date_unique');
            });
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('wallet_center_checkin_logs')) {
            return;
        }

        try {
            Schema::table('wallet_center_checkin_logs', function (Blueprint $table): void {
                $table->dropUnique('wallet_center_checkin_user_date_unique');
            });
        } catch (\Throwable) {
        }
    }
};
