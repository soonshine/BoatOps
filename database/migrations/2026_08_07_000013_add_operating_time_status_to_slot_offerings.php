<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slot_offerings', function (Blueprint $table): void {
            $table->string('operating_time_status')
                ->default('UNVERIFIED')
                ->after('status');
        });

        DB::table('slot_offerings')
            ->where('kind', 'PRESET')
            ->whereIn('code', [
                'FULL_DAY_8H',
                'FULL_DAY_6H',
                'AM_4H',
                'PM_4H',
                'PM_2_5H',
            ])
            ->update(['operating_time_status' => 'DEMO_DEFAULT_UNVERIFIED']);
    }

    public function down(): void
    {
        Schema::table('slot_offerings', function (Blueprint $table): void {
            $table->dropColumn('operating_time_status');
        });
    }
};
