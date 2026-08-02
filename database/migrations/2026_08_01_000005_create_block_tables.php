<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boat_id')->constrained()->cascadeOnDelete();
            $table->string('external_reference');
            $table->string('status')->default('ACTIVE');
            $table->string('reason_code');
            $table->string('reason')->nullable();
            $table->timestampTz('business_start');
            $table->timestampTz('business_end');
            $table->timestampTz('occupied_start');
            $table->timestampTz('occupied_end');
            $table->unsignedBigInteger('allocation_id')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'external_reference']);
            $table->index(['organization_id', 'status', 'business_start']);
        });

        Schema::table('allocations', function (Blueprint $table): void {
            $table->foreignId('block_id')->nullable()->after('booking_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('allocations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('block_id');
        });
        Schema::dropIfExists('blocks');
    }
};
