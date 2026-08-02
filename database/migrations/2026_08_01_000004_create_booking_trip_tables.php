<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hold_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('boat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_template_id')->constrained()->cascadeOnDelete();
            $table->string('external_reference');
            $table->string('status')->default('CONFIRMED');
            $table->timestampTz('business_start');
            $table->timestampTz('business_end');
            $table->unsignedBigInteger('allocation_id');
            $table->timestampTz('confirmed_at');
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'external_reference']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('trips', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('boat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_template_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('PLANNED');
            $table->timestampTz('planned_start');
            $table->timestampTz('planned_end');
            $table->timestamps();
            $table->index(['organization_id', 'status', 'planned_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
        Schema::dropIfExists('bookings');
    }
};
