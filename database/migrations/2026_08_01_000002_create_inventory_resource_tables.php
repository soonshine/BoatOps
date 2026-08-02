<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('status')->default('ACTIVE');
            $table->unsignedInteger('buffer_before_minutes')->default(0);
            $table->unsignedInteger('buffer_after_minutes')->default(0);
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });

        Schema::create('trip_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('status')->default('ACTIVE');
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boat_id')->constrained()->cascadeOnDelete();
            $table->string('allocation_type');
            $table->string('status')->default('ACTIVE');
            $table->timestampTz('business_start');
            $table->timestampTz('business_end');
            $table->timestampTz('occupied_start');
            $table->timestampTz('occupied_end');
            $table->unsignedBigInteger('hold_id')->nullable();
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'boat_id', 'status'], 'allocations_overlap_lookup');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement(<<<'SQL'
                ALTER TABLE allocations
                ADD COLUMN occupied_range tstzrange
                GENERATED ALWAYS AS (tstzrange(occupied_start, occupied_end, '[)')) STORED
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE allocations
                ADD CONSTRAINT allocations_no_active_overlap
                EXCLUDE USING gist (
                    organization_id WITH =,
                    boat_id WITH =,
                    occupied_range WITH &&
                ) WHERE (status = 'ACTIVE')
                DEFERRABLE INITIALLY IMMEDIATE
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('allocations');
        Schema::dropIfExists('trip_templates');
        Schema::dropIfExists('boats');
    }
};
